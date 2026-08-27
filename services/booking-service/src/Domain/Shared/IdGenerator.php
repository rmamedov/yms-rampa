<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Генератор ідентифікаторів агрегатів (UUID). Винесений в інтерфейс,
 * щоб тести могли працювати з передбачуваними id.
 */
interface IdGenerator
{
    public function generate(): string;
}
