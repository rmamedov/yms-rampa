<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\BranchPresenter;
use App\Application\Dto\Payload;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\ConfigurationReadiness;
use App\Domain\Event\EventPublisher;
use App\Domain\Event\StoreConfigChanged;
use App\Domain\Shared\Clock;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\ValidationException;

/**
 * Редагування YMS-полів магазину — вкладка «Загальне» картки магазину (5.3.1).
 * MCP-поля недоступні для редагування жодній ролі (INT-03, STC-01).
 */
final readonly class StoreAdminService
{
    public function __construct(
        private BranchRepository $branches,
        private StoreCatalogService $catalog,
        private EventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * Часткове оновлення YMS-полів (STC-02, STC-03, STC-04, STC-07).
     *
     * @return array<string, mixed>
     */
    public function updateYmsFields(string $branchId, Payload $payload): array
    {
        $branch = $this->catalog->requireBranch($branchId);
        $now = $this->clock->now();

        foreach (array_keys($payload->toArray()) as $field) {
            if (\in_array($field, ['branchId', 'companyId', 'externalId', 'city', 'address', 'latitude', 'longitude', 'hasPickup', 'open'], true)) {
                throw new ValidationException(
                    \sprintf('Поле «%s» надходить з MCP і не редагується в адмін-панелі', $field),
                    'MCP_FIELD_READ_ONLY',
                    [$field => 'Поле з MCP, редагування неможливе'],
                );
            }
        }

        if ($payload->has('displayName')) {
            $branch->rename($payload->string('displayName'), $now);
        }

        if ($payload->has('phone')) {
            $branch->setPhone($payload->string('phone'), $now);
        }

        if ($payload->has('addressOverride')) {
            $branch->setAddressOverride($payload->string('addressOverride'), $now);
        }

        if ($payload->has('ymsStatus')) {
            $branch->changeStatus($this->status($payload->requireString('ymsStatus')), $this->readiness($branchId), $now);
        }

        if ($payload->has('visibleToSuppliers')) {
            $branch->setVisibleToSuppliers($payload->requireBool('visibleToSuppliers'), $now);
        }

        $this->branches->save($branch);
        $this->events->publish(new StoreConfigChanged($branchId, 'yms_fields', null, null, $now));

        return BranchPresenter::card($branch, $this->catalog->effectiveConfig($branchId, $now));
    }

    /**
     * Масова зміна статусу для вибраних магазинів (UI-02).
     *
     * @param list<string> $branchIds
     *
     * @return array<string, mixed> зведення «успішно / з помилкою» по кожному обʼєкту
     */
    public function bulkChangeStatus(array $branchIds, string $status): array
    {
        $target = $this->status($status);
        $now = $this->clock->now();
        $succeeded = [];
        $failed = [];

        foreach ($branchIds as $branchId) {
            try {
                $branch = $this->catalog->requireBranch($branchId);
                $branch->changeStatus($target, $this->readiness($branchId), $now);
                $this->branches->save($branch);
                $succeeded[] = $branchId;
            } catch (DomainException $e) {
                $failed[] = ['branchId' => $branchId, 'message' => $e->getMessage(), 'code' => $e->errorCode()];
            }
        }

        return [
            'requested' => \count($branchIds),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    private function status(string $value): YmsStatus
    {
        $status = YmsStatus::tryFrom($value);

        if (!$status instanceof YmsStatus) {
            throw ValidationException::field(
                'ymsStatus',
                \sprintf('Невідомий статус «%s»; допустимі: %s', $value, implode(', ', YmsStatus::values())),
            );
        }

        return $status;
    }

    private function readiness(string $branchId): ConfigurationReadiness
    {
        return $this->catalog->effectiveConfig($branchId)?->readiness() ?? ConfigurationReadiness::absent();
    }
}
