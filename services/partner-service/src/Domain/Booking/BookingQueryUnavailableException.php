<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Shared\DomainException;

/**
 * booking-service не відповів або відповів не за контрактом.
 *
 * Це НЕ бізнес-правило SUP-06: домен не дізнався, чи є в постачальника
 * бронювання, тому не має права ні дозволити видалення, ні стверджувати, що
 * історія існує. Обидві відповіді були б вигадкою.
 *
 * Виняток — СВІДОМИЙ запасний варіант: видалення не відбувається (консервативно
 * і безпечно), але користувач бачить справжню причину — «сусідній сервіс
 * недоступний, спробуйте пізніше», а не хибне «постачальник має бронювання».
 * Саме підміна цих двох станів і робила довідник постачальників невидаляним.
 *
 * Два випадки навмисно розрізняються (як в IdentityUnavailableException):
 *   - 503 BOOKING_QUERY_UNAVAILABLE  — мережа, таймаут, 5xx: повтор має сенс;
 *   - 502 BOOKING_QUERY_BAD_RESPONSE — відповідь прийшла, але це не контракт
 *     (не JSON, немає поля hasAnyBookings): повтор не допоможе.
 */
final class BookingQueryUnavailableException extends DomainException
{
    public const ERROR_CODE = 'BOOKING_QUERY_UNAVAILABLE';
    public const BAD_RESPONSE_CODE = 'BOOKING_QUERY_BAD_RESPONSE';

    private function __construct(
        string $message,
        string $errorCode,
        private readonly int $status,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 'Сервіс бронювань недоступний', $previous);
    }

    /**
     * Сусід недосяжний: таймаут, обрив, відмова зʼєднання, DNS.
     *
     * @param string $outcome що саме НЕ сталося, у формі результату:
     *                        «постачальника не видалено»
     */
    public static function unreachable(string $outcome, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf(
                'Сервіс бронювань тимчасово недоступний, тому перевірити історію поставок неможливо: %s (%s). Спробуйте ще раз за кілька хвилин.',
                $outcome,
                $reason,
            ),
            self::ERROR_CODE,
            503,
            $previous,
        );
    }

    /** Сусід відповів статусом, якого контракт не передбачає. */
    public static function rejected(string $outcome, int $httpStatus): self
    {
        return self::unreachable($outcome, \sprintf('HTTP %d', $httpStatus));
    }

    /** Відповідь неможливо розібрати або в ній немає обовʼязкових полів. */
    public static function badResponse(string $outcome, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf(
                'Сервіс бронювань відповів неочікувано: %s (%s). Зверніться до підтримки.',
                $outcome,
                $reason,
            ),
            self::BAD_RESPONSE_CODE,
            502,
            $previous,
        );
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
