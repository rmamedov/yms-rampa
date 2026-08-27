<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Подія BranchSynced — філія створена або оновлена за даними MCP (INT-07).
 */
final readonly class BranchSynced implements DomainEvent
{
    /**
     * @param 'created'|'updated'|'archived' $changeType
     * @param array<string, array{old: mixed, new: mixed}> $changedFields
     */
    public function __construct(
        public string $branchId,
        public string $externalId,
        public string $changeType,
        public array $changedFields,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public function name(): string
    {
        return 'BranchSynced';
    }

    public function payload(): array
    {
        return [
            'branchId' => $this->branchId,
            'externalId' => $this->externalId,
            'changeType' => $this->changeType,
            'changedFields' => $this->changedFields,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
