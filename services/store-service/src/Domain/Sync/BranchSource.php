<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Джерело довідника філій. У проді — MCP Сільпо (silpo_list_branches з пагінацією
 * limit=500/offset, INT-01), у розробці й тестах — фікстура.
 *
 * Реалізація зобовʼязана або віддати ПОВНУ вибірку, або кинути BranchSourceException:
 * часткова вибірка вважається невдалим синком і не застосовується до БД (INT-01, SYNC-04).
 */
interface BranchSource
{
    /**
     * Сирі записи MCP. Кожен запис — асоціативний масив з полями контракту 11.1.1.
     *
     * @return iterable<array<string, mixed>>
     *
     * @throws BranchSourceException при недоступності джерела або обриві пагінації
     */
    public function fetchAll(): iterable;

    /** Людинозрозуміла назва джерела для журналу синхронізацій. */
    public function describe(): string;
}
