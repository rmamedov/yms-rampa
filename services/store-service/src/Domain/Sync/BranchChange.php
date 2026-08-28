<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Один поіменний рядок деталізації синхронізації (SYNC-01).
 *
 * Лічильники журналу відповідають на «скільки», але не на «які саме»:
 * після запуску користувач бачив «змінено 12», не знаючи, які це філії й
 * що саме в них змінилося. Синхронізатор цей перелік уже рахував —
 * до журналу він просто не доходив.
 */
final readonly class BranchChange
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $fields зміни полів MCP (лише для kind=updated)
     */
    public function __construct(
        public BranchChangeKind $kind,
        public string $branchId,
        public string $externalId,
        public array $fields = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'kindLabel' => $this->kind->label(),
            'branchId' => $this->branchId,
            'externalId' => $this->externalId,
            'fields' => $this->fields,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $fields = [];

        foreach ((array) ($row['fields'] ?? []) as $name => $change) {
            $change = (array) $change;
            $fields[(string) $name] = [
                'old' => $change['old'] ?? null,
                'new' => $change['new'] ?? null,
            ];
        }

        return new self(
            kind: BranchChangeKind::tryFrom((string) ($row['kind'] ?? '')) ?? BranchChangeKind::Updated,
            branchId: (string) ($row['branchId'] ?? ''),
            externalId: (string) ($row['externalId'] ?? ''),
            fields: $fields,
        );
    }
}
