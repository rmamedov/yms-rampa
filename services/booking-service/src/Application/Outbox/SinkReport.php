<?php

declare(strict_types=1);

namespace App\Application\Outbox;

/**
 * Що споживач зробив з кожною подією доставленого пакета.
 *
 * Звіт поіменний, а не зведений: релей прибирає з черги лише ті записи, які
 * споживач справді прийняв, тож йому потрібен присуд для КОЖНОЇ події, а не
 * лічильники. Лічильники лишаються для журналу.
 */
final readonly class SinkReport
{
    /**
     * @param array<int, EventOutcome> $outcomes присуд за позицією події в пакеті
     * @param array<int, string|null>  $reasons  пояснення споживача за тією ж позицією
     */
    public function __construct(
        private array $outcomes = [],
        private array $reasons = [],
    ) {
    }

    /**
     * @param list<array{index: int, outcome: EventOutcome, reason: string|null}> $rows
     */
    public static function fromRows(array $rows): self
    {
        $outcomes = [];
        $reasons = [];

        foreach ($rows as $row) {
            $outcomes[$row['index']] = $row['outcome'];
            $reasons[$row['index']] = $row['reason'];
        }

        return new self($outcomes, $reasons);
    }

    /** Присуд для події, що стояла в пакеті на позиції $index. */
    public function outcomeAt(int $index): ?EventOutcome
    {
        return $this->outcomes[$index] ?? null;
    }

    public function reasonAt(int $index): ?string
    {
        return $this->reasons[$index] ?? null;
    }

    public function count(EventOutcome $outcome): int
    {
        return \count(array_filter($this->outcomes, static fn (EventOutcome $o): bool => $o === $outcome));
    }

    /**
     * Присуди, що НЕ дають прибрати запис із черги, разом із причинами.
     *
     * @return list<array{index: int, outcome: EventOutcome, reason: string|null}>
     */
    public function undelivered(): array
    {
        $rows = [];

        foreach ($this->outcomes as $index => $outcome) {
            if (!$outcome->isDelivered()) {
                $rows[] = ['index' => $index, 'outcome' => $outcome, 'reason' => $this->reasons[$index] ?? null];
            }
        }

        return $rows;
    }

    public function plus(self $other): self
    {
        // Позиції нумеруються в межах ОДНОГО пакета, тому зведений звіт
        // зберігає лише лічильники: підсумок по позиціях був би безглуздим.
        $shift = [] === $this->outcomes ? 0 : max(array_keys($this->outcomes)) + 1;
        $outcomes = $this->outcomes;
        $reasons = $this->reasons;

        foreach ($other->outcomes as $index => $outcome) {
            $outcomes[$shift + $index] = $outcome;
            $reasons[$shift + $index] = $other->reasons[$index] ?? null;
        }

        return new self($outcomes, $reasons);
    }

    /** Ознака, що пакет доїхав, але частина подій до read-моделей не дійшла. */
    public function hasProblems(): bool
    {
        return [] !== $this->undelivered();
    }
}
