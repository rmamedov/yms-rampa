<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\ConfigurationPresenter;
use App\Application\Dto\Payload;
use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\SlotBlockRepository;
use App\Domain\Event\EventPublisher;
use App\Domain\Event\SlotReleased;
use App\Domain\Event\StoreConfigChanged;
use App\Domain\Shared\Clock;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;

/**
 * Разові блокування слотів — вкладка «Блокування слотів» (5.3.6).
 *
 * STC-50: дата, діапазон часу, рампи (одна/кілька/усі), обовʼязкова причина.
 * STC-52: дострокове зняття блокування звільняє слоти подією SlotReleased.
 */
final readonly class SlotBlockService
{
    public function __construct(
        private SlotBlockRepository $blocks,
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
            static fn (SlotBlock $b): array => ConfigurationPresenter::slotBlock($b),
            $this->blocks->findForStore($storeId, $activeOnly),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $storeId, Payload $payload, ?string $createdBy = null): array
    {
        $this->catalog->requireBranch($storeId);
        $now = $this->clock->now();

        $blockFrom = $payload->requireDateTime('blockFrom');
        $blockTo = $payload->requireDateTime('blockTo');

        // STC-60: для блокувань чинність можлива з поточного дня, але не заднім числом.
        if ($blockTo <= $now) {
            throw ValidationException::config(
                'Блокування не можна створити на минулий період',
                ['blockTo' => 'Період блокування вже минув'],
            );
        }

        $rampIds = $payload->stringList('rampIds');
        $config = $this->catalog->effectiveConfig($storeId, $now);

        foreach ($rampIds as $rampId) {
            if (null !== $config && null === $config->ramp($rampId)) {
                throw ValidationException::config(
                    \sprintf('Рампу «%s» не знайдено в конфігурації магазину', $rampId),
                    ['rampIds' => 'Оберіть рампи зі списку магазину'],
                );
            }
        }

        $block = new SlotBlock(
            id: Uuid::v4(),
            storeId: $storeId,
            rampIds: $rampIds,
            blockFrom: $blockFrom,
            blockTo: $blockTo,
            reason: $payload->string('reason') ?? '',
            createdBy: $createdBy,
            createdAt: $now,
        );

        $this->blocks->save($block);
        $this->events->publish(new StoreConfigChanged($storeId, 'slot_block', null, $blockFrom, $now));

        return ConfigurationPresenter::slotBlock($block);
    }

    /**
     * STC-52: дострокове зняття блокування; звільнені слоти повертаються в available
     * з подією SlotReleased.
     *
     * @return array<string, mixed>
     */
    public function release(string $storeId, string $blockId): array
    {
        $block = $this->requireBlock($storeId, $blockId);
        $now = $this->clock->now();

        if ($block->isReleased()) {
            throw ValidationException::config(
                'Блокування вже знято',
                ['releasedAt' => 'Блокування вже знято'],
            );
        }

        $released = $block->release($now);
        $this->blocks->save($released);

        $this->events->publish(new SlotReleased(
            storeId: $storeId,
            rampIds: $released->rampIds,
            from: $released->blockFrom,
            to: $released->blockTo,
            reason: $released->reason,
            occurredAt: $now,
        ));

        return ConfigurationPresenter::slotBlock($released);
    }

    public function delete(string $storeId, string $blockId): void
    {
        $block = $this->requireBlock($storeId, $blockId);
        $this->blocks->delete($block->id);
        $this->events->publish(new StoreConfigChanged($storeId, 'slot_block', null, null, $this->clock->now()));
    }

    private function requireBlock(string $storeId, string $blockId): SlotBlock
    {
        $block = $this->blocks->find($blockId);

        if (!$block instanceof SlotBlock || $block->storeId !== $storeId) {
            throw NotFoundException::slotBlock($blockId);
        }

        return $block;
    }
}
