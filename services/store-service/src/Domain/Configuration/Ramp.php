<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Рампа магазину (STC-21, STC-22): номер унікальний у межах магазину,
 * назва опційна. Вимкнена рампа (active=false) не бере участі в генерації слотів.
 */
final readonly class Ramp
{
    public const int NAME_MAX_LENGTH = 60;

    public function __construct(
        public string $rampId,
        public int $number,
        public ?string $name = null,
        public bool $active = true,
    ) {
        if ('' === trim($rampId)) {
            throw ValidationException::config('Ідентифікатор рампи не може бути порожнім', ['rampId' => 'Обовʼязкове поле']);
        }

        if ($number < 1) {
            throw ValidationException::config(
                'Номер рампи має бути цілим числом ≥ 1',
                ['number' => 'Номер рампи має бути ≥ 1'],
            );
        }

        if (null !== $name && mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw ValidationException::config(
                \sprintf('Назва рампи не може перевищувати %d символів', self::NAME_MAX_LENGTH),
                ['name' => \sprintf('Максимум %d символів', self::NAME_MAX_LENGTH)],
            );
        }
    }

    public function displayName(): string
    {
        return $this->name ?? \sprintf('Рампа %d', $this->number);
    }

    public function disabled(): self
    {
        return new self($this->rampId, $this->number, $this->name, false);
    }

    /** @return array{rampId: string, number: int, name: string|null, active: bool} */
    public function toArray(): array
    {
        return [
            'rampId' => $this->rampId,
            'number' => $this->number,
            'name' => $this->name,
            'active' => $this->active,
        ];
    }
}
