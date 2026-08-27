<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Виняток календаря на конкретну дату (STC-12, STC-13).
 * Має пріоритет над тижневим шаблоном; причина обовʼязкова (до 200 символів).
 */
final readonly class CalendarException
{
    public const int REASON_MAX_LENGTH = 200;
    public const int MAX_DAYS_AHEAD = 365;

    /** @var list<TimeInterval> */
    public array $intervals;

    public string $reason;

    /**
     * @param string             $date      локальна дата магазину у форматі YYYY-MM-DD
     * @param list<TimeInterval> $intervals заповнюється лише для type=custom
     */
    public function __construct(
        public string $date,
        public CalendarExceptionType $type,
        string $reason,
        array $intervals = [],
    ) {
        self::assertDateFormat($date);

        $reason = trim($reason);

        if ('' === $reason) {
            throw ValidationException::config(
                'Поле «Причина» для винятку календаря обовʼязкове',
                ['reason' => 'Вкажіть причину винятку'],
            );
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::config(
                \sprintf('Причина не може перевищувати %d символів', self::REASON_MAX_LENGTH),
                ['reason' => \sprintf('Максимум %d символів', self::REASON_MAX_LENGTH)],
            );
        }

        if (CalendarExceptionType::Closed === $type && [] !== $intervals) {
            throw ValidationException::config(
                'Для винятку типу «вихідний» інтервали прийому не задаються',
                ['intervals' => 'Для вихідного дня інтервали не задаються'],
            );
        }

        if (CalendarExceptionType::Custom === $type && [] === $intervals) {
            throw ValidationException::config(
                'Для винятку типу «змінений графік» потрібен щонайменше один інтервал',
                ['intervals' => 'Додайте щонайменше один інтервал'],
            );
        }

        // Перевірка перетинів усередині дати винятку — те саме правило, що й для тижневого шаблону.
        $window = new ReceivingWindow(1, $intervals);

        $this->reason = $reason;
        $this->intervals = $window->intervals;
    }

    /**
     * Виняток не може бути в минулому і створюється не далі ніж на 365 днів уперед (STC-13).
     *
     * @param string $today локальна дата магазину «сьогодні» у форматі YYYY-MM-DD
     */
    public function assertWithinAllowedRange(string $today): void
    {
        if ($this->date < $today) {
            throw ValidationException::config(
                'Дата винятку не може бути в минулому',
                ['date' => 'Дата винятку не може бути в минулому'],
            );
        }

        $limit = (new \DateTimeImmutable($today))->modify(\sprintf('+%d days', self::MAX_DAYS_AHEAD))->format('Y-m-d');

        if ($this->date > $limit) {
            throw ValidationException::config(
                \sprintf('Виняток можна створити не більш ніж на %d днів уперед', self::MAX_DAYS_AHEAD),
                ['date' => \sprintf('Максимум %d днів уперед', self::MAX_DAYS_AHEAD)],
            );
        }
    }

    public function isClosed(): bool
    {
        return CalendarExceptionType::Closed === $this->type;
    }

    private static function assertDateFormat(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::config(
                \sprintf('Дата «%s» має бути у форматі YYYY-MM-DD', $date),
                ['date' => 'Очікується формат YYYY-MM-DD'],
            );
        }
    }
}
