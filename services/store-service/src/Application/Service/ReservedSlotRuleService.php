<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\ConfigurationPresenter;
use App\Application\Dto\Payload;
use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\ReservedSlotRuleRepository;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Event\EventPublisher;
use App\Domain\Event\StoreConfigChanged;
use App\Domain\Shared\Clock;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;

/**
 * CRUD правил резервування слотів — вкладка «Резерви» (5.3.5).
 *
 * STC-42: резерв не можна створити на час поза вікнами прийому або на вимкнену рампу;
 * перетин двох правил резерву на один слот заборонений.
 */
final readonly class ReservedSlotRuleService
{
    public function __construct(
        private ReservedSlotRuleRepository $rules,
        private StoreCatalogService $catalog,
        private EventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $storeId, ?bool $activeOnly = null): array
    {
        $this->catalog->requireBranch($storeId);

        return array_map(
            static fn (ReservedSlotRule $r): array => ConfigurationPresenter::reservedSlotRule($r),
            $this->rules->findForStore($storeId, $activeOnly),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $storeId, Payload $payload, ?string $createdBy = null): array
    {
        $this->catalog->requireBranch($storeId);
        $now = $this->clock->now();

        $rule = new ReservedSlotRule(
            id: Uuid::v4(),
            storeId: $storeId,
            supplierId: $payload->requireString('supplierId'),
            rampId: $payload->requireString('rampId'),
            slotStartTime: $payload->requireString('slotStartTime'),
            dayOfWeek: $payload->int('dayOfWeek'),
            date: $payload->string('date'),
            validFrom: $payload->dateTime('validFrom') ?? $now,
            validTo: $payload->dateTime('validTo'),
            active: $payload->bool('active', true) ?? true,
            createdBy: $createdBy,
            createdAt: $now,
        );

        $this->assertFitsConfiguration($rule);
        $this->assertNoOverlap($rule);

        $this->rules->save($rule);
        $this->events->publish(new StoreConfigChanged($storeId, 'reserved_slot_rule', null, $rule->validFrom, $now));

        return ConfigurationPresenter::reservedSlotRule($rule);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $storeId, string $ruleId, Payload $payload): array
    {
        $existing = $this->requireRule($storeId, $ruleId);
        $now = $this->clock->now();

        $rule = new ReservedSlotRule(
            id: $existing->id,
            storeId: $existing->storeId,
            supplierId: $payload->string('supplierId') ?? $existing->supplierId,
            rampId: $payload->string('rampId') ?? $existing->rampId,
            slotStartTime: $payload->string('slotStartTime') ?? $existing->slotStartTime,
            dayOfWeek: $payload->has('dayOfWeek') ? $payload->int('dayOfWeek') : $existing->dayOfWeek,
            date: $payload->has('date') ? $payload->string('date') : $existing->date,
            validFrom: $payload->dateTime('validFrom') ?? $existing->validFrom,
            validTo: $payload->has('validTo') ? $payload->dateTime('validTo') : $existing->validTo,
            active: $payload->bool('active', $existing->active) ?? $existing->active,
            createdBy: $existing->createdBy,
            createdAt: $existing->createdAt,
        );

        $this->assertFitsConfiguration($rule);
        $this->assertNoOverlap($rule);

        $this->rules->save($rule);
        $this->events->publish(new StoreConfigChanged($storeId, 'reserved_slot_rule', null, $rule->validFrom, $now));

        return ConfigurationPresenter::reservedSlotRule($rule);
    }

    public function delete(string $storeId, string $ruleId): void
    {
        $rule = $this->requireRule($storeId, $ruleId);
        $this->rules->delete($rule->id);
        $this->events->publish(new StoreConfigChanged($storeId, 'reserved_slot_rule', null, null, $this->clock->now()));
    }

    private function requireRule(string $storeId, string $ruleId): ReservedSlotRule
    {
        $rule = $this->rules->find($ruleId);

        if (!$rule instanceof ReservedSlotRule || $rule->storeId !== $storeId) {
            throw NotFoundException::reservedSlotRule($ruleId);
        }

        return $rule;
    }

    /**
     * STC-42: час резерву має потрапляти у вікно прийому, рампа має бути ввімкненою.
     */
    private function assertFitsConfiguration(ReservedSlotRule $rule): void
    {
        $config = $this->catalog->effectiveConfig($rule->storeId)
            ?? $this->latestConfigOrNull($rule->storeId);

        if (!$config instanceof StoreConfiguration) {
            throw ConflictException::storeNotConfigured(
                'Резерв неможливий: для магазину ще не задано конфігурацію прийому',
            );
        }

        if (null === $config->ramp($rule->rampId)) {
            throw ValidationException::config(
                \sprintf('Рампу «%s» не знайдено в конфігурації магазину', $rule->rampId),
                ['rampId' => 'Оберіть рампу зі списку магазину'],
            );
        }

        if (!$config->isRampActive($rule->rampId)) {
            throw ValidationException::config(
                'Резерв не можна створити на вимкнену рампу',
                ['rampId' => 'Рампа вимкнена'],
            );
        }

        if (!$config->isWithinReceivingWindow($rule->effectiveDayOfWeek(), $rule->slotStartTime)) {
            throw ValidationException::config(
                'Час резерву не потрапляє в жодне вікно прийому',
                ['slotStartTime' => 'Час резерву не потрапляє в жодне вікно прийому'],
            );
        }
    }

    private function assertNoOverlap(ReservedSlotRule $rule): void
    {
        foreach ($this->rules->findForStore($rule->storeId, true) as $existing) {
            if ($rule->conflictsWith($existing)) {
                throw ConflictException::reservedRuleOverlap();
            }
        }
    }

    private function latestConfigOrNull(string $storeId): ?StoreConfiguration
    {
        return $this->catalog->effectiveConfig($storeId, $this->clock->now()->modify('+30 days'));
    }
}
