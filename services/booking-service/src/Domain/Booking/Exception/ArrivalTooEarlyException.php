<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Booking\ArrivalWindow;
use App\Domain\Exception\ProblemException;

/**
 * Спроба відмітити прибуття раніше за добу візиту (розділ 8, ISSUE-13).
 *
 * Правило доменне, а не інтерфейсне: кнопку в застосунку водія можна обійти
 * прямим викликом `POST /api/driver/v1/bookings/{id}/arrived`, тому відмову
 * тримає агрегат.
 *
 * Повідомлення обовʼязково називає, КОЛИ відмітка стане доступною: інакше
 * водій бачить заборону без виходу з неї.
 */
final class ArrivalTooEarlyException extends ProblemException
{
    public const string ERROR_CODE = 'ARRIVAL_TOO_EARLY';

    public function __construct(private readonly ArrivalWindow $window)
    {
        parent::__construct(\sprintf(
            'Відмітити прибуття можна в день візиту — %s (слот о %s).',
            $window->localDayLabel(),
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
            'localSlotTime' => $this->window->localSlotTime,
            'availableFrom' => $this->window->dayOfVisitStart
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
