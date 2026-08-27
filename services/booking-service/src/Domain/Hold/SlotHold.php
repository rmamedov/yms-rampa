<?php

declare(strict_types=1);

namespace App\Domain\Hold;

use App\Domain\Slot\SlotKey;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Тимчасовий холд слота (HOLD-01..HOLD-04). Живе тільки в Redis і в MongoDB
 * не зберігається; це оптимістичний UX-механізм, а не гарантія унікальності.
 */
final readonly class SlotHold
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $expiresAt;
    public DateTimeImmutable $maxExpiresAt;

    public function __construct(
        public SlotKey $slotKey,
        public string $holdToken,
        public string $ownerUserId,
        public ?string $supplierId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $maxExpiresAt,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->expiresAt = $expiresAt->setTimezone($utc);
        $this->maxExpiresAt = $maxExpiresAt->setTimezone($utc);
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() >= $this->expiresAt->getTimestamp();
    }

    /** HOLD-02: сумарна тривалість холду вичерпана (holdMaxMinutes). */
    public function isExhaustedAt(DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() >= $this->maxExpiresAt->getTimestamp();
    }

    public function isOwnedBy(string $holdToken): bool
    {
        return hash_equals($this->holdToken, $holdToken);
    }

    /** Скільки секунд лишилося до зняття холду — для таймера в UI. */
    public function secondsLeft(DateTimeImmutable $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'storeId' => $this->slotKey->storeId,
            'rampId' => $this->slotKey->rampId,
            'slotStart' => $this->slotKey->slotStart->format('Y-m-d\TH:i:s\Z'),
            'holdToken' => $this->holdToken,
            'expiresAt' => $this->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'maxExpiresAt' => $this->maxExpiresAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
