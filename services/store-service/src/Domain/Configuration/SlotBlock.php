<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Разове блокування слотів (STC-50..STC-52, 10.2.3).
 *
 * Заблоковані слоти отримують slot.state=blocked і недоступні всім постачальникам.
 * Дострокове зняття блокування звільняє слоти подією SlotReleased.
 */
final readonly class SlotBlock
{
    public const int REASON_MAX_LENGTH = 200;
    public const int SCHEMA_VERSION = 1;

    /** @var list<string> порожній список = усі рампи магазину */
    public array $rampIds;

    public string $reason;

    /**
     * @param list<string> $rampIds
     */
    public function __construct(
        public string $id,
        public string $storeId,
        array $rampIds,
        public \DateTimeImmutable $blockFrom,
        public \DateTimeImmutable $blockTo,
        string $reason,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $releasedAt = null,
    ) {
        if ('' === trim($storeId)) {
            throw ValidationException::config('Не вказано магазин блокування', ['storeId' => 'Обовʼязкове поле']);
        }

        if ($blockTo <= $blockFrom) {
            throw ValidationException::config(
                'Кінець блокування має бути пізнішим за початок',
                ['blockTo' => 'Кінець блокування має бути пізнішим за початок'],
            );
        }

        $reason = trim($reason);

        if ('' === $reason) {
            throw ValidationException::config(
                'Причина блокування обовʼязкова',
                ['reason' => 'Вкажіть причину блокування'],
            );
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::config(
                \sprintf('Причина не може перевищувати %d символів', self::REASON_MAX_LENGTH),
                ['reason' => \sprintf('Максимум %d символів', self::REASON_MAX_LENGTH)],
            );
        }

        $this->reason = $reason;
        $this->rampIds = array_values(array_unique(array_filter($rampIds, static fn (string $r): bool => '' !== trim($r))));
    }

    /** Блокування без переліку рамп поширюється на всі рампи магазину. */
    public function coversAllRamps(): bool
    {
        return [] === $this->rampIds;
    }

    public function coversRamp(string $rampId): bool
    {
        return $this->coversAllRamps() || \in_array($rampId, $this->rampIds, true);
    }

    public function isReleased(): bool
    {
        return null !== $this->releasedAt;
    }

    public function isActiveAt(\DateTimeImmutable $moment): bool
    {
        return !$this->isReleased() && $moment >= $this->blockFrom && $moment < $this->blockTo;
    }

    public function overlaps(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return !$this->isReleased() && $this->blockFrom < $to && $from < $this->blockTo;
    }

    /** Дострокове зняття блокування (STC-52). */
    public function release(\DateTimeImmutable $at): self
    {
        if ($this->isReleased()) {
            return $this;
        }

        return new self(
            id: $this->id,
            storeId: $this->storeId,
            rampIds: $this->rampIds,
            blockFrom: $this->blockFrom,
            blockTo: $this->blockTo,
            reason: $this->reason,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            releasedAt: $at,
        );
    }
}
