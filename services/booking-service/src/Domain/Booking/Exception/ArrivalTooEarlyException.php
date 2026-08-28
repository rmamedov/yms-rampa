<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Booking\ArrivalWindow;
use App\Domain\Exception\ProblemException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Спроба відмітити прибуття до відкриття вікна (розділ 8, ISSUE-13).
 *
 * Правило доменне, а не інтерфейсне: кнопку в застосунку водія можна обійти
 * прямим викликом `POST /api/driver/v1/bookings/{id}/arrived`, тому відмову
 * тримає агрегат.
 *
 * Повідомлення обовʼязково називає ЧАС, коли відмітка стане доступною —
 * інакше водій бачить заборону без виходу з неї. Дата додається лише тоді,
 * коли вікно відкриється не сьогодні: для звичайного випадку («ще рано,
 * приходьте за годину») дата тільки заважає.
 */
final class ArrivalTooEarlyException extends ProblemException
{
    public const string ERROR_CODE = 'ARRIVAL_TOO_EARLY';

    public function __construct(private readonly ArrivalWindow $window, DateTimeImmutable $now)
    {
        parent::__construct(\sprintf(
            'Відмітити прибуття можна з %s%s, слот о %s.',
            $window->localOpensAt,
            $window->isSameLocalDay($now) ? '' : ' '.$window->opensOnLocalDate(),
            $window->localSlotTime,
        ));
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function problemExtensions(): array
    {
        return [
            'localDate' => $this->window->localDate,
            'localOpensAt' => $this->window->localOpensAt,
            'localSlotTime' => $this->window->localSlotTime,
            'opensAt' => $this->window->opensAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
