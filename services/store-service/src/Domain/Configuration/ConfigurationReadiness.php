<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

/**
 * Ознака «Налаштовано» (STL-04): магазин налаштований тоді і тільки тоді, коли задані
 * одночасно щонайменше одне вікно прийому, розмір слоту, щонайменше одна активна рампа
 * і maxVehicleWeightTons. Обчислюється store-service, а не фронтендом.
 */
final readonly class ConfigurationReadiness
{
    /**
     * @param list<string> $missing перелік відсутніх параметрів українською
     */
    private function __construct(
        public bool $complete,
        public array $missing,
    ) {
    }

    /** @param list<string> $missing */
    public static function of(array $missing): self
    {
        return new self([] === $missing, $missing);
    }

    /** Конфігурації для магазину взагалі немає. */
    public static function absent(): self
    {
        return new self(false, [
            'вікна прийому',
            'розмір слоту',
            'активні рампи',
            'максимальна маса авто',
        ]);
    }

    public function missingAsText(): string
    {
        return implode(', ', $this->missing);
    }
}
