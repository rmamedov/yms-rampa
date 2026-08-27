<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Throwable;

/**
 * Сусідній мікросервіс (store-service, partner-service) не відповів або
 * відповів так, що його неможливо розібрати.
 *
 * Це НЕ помилка бронювання і не «магазину не існує»: домен просто не отримав
 * даних, щоб ухвалити рішення. Окремий доменний виняток потрібен, щоб клієнт
 * побачив зрозумілий problem+json з кодом, а не 500 зі стектрейсом, і щоб у
 * журналі було видно, який саме сусід підвів.
 *
 * Розрізняються два випадки:
 *   - 503 UPSTREAM_UNAVAILABLE  — мережа, таймаут, 5xx чи будь-який інший
 *     несподіваний статус: спроба має сенс пізніше;
 *   - 502 UPSTREAM_BAD_RESPONSE — відповідь прийшла, але це не той контракт
 *     (не JSON, не обʼєкт, поля не збираються в доменні типи): повтор не
 *     допоможе, потрібне втручання.
 */
final class UpstreamUnavailableException extends ProblemException
{
    public const string ERROR_CODE = 'UPSTREAM_UNAVAILABLE';
    public const string BAD_RESPONSE_CODE = 'UPSTREAM_BAD_RESPONSE';

    /**
     * @param string $service імʼя сусіда так, як він зветься у внутрішньому
     *                        шлюзі: store-service, partner-service
     */
    private function __construct(
        public readonly string $service,
        private readonly string $problemCode,
        private readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function storeService(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            'store-service',
            self::ERROR_CODE,
            503,
            \sprintf('Сервіс налаштувань філій тимчасово недоступний (%s)', $reason),
            $previous,
        );
    }

    public static function partnerService(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            'partner-service',
            self::ERROR_CODE,
            503,
            \sprintf('Сервіс постачальників тимчасово недоступний (%s)', $reason),
            $previous,
        );
    }

    public static function badResponse(string $service, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            $service,
            self::BAD_RESPONSE_CODE,
            502,
            \sprintf('Сервіс %s повернув відповідь, яку не вдалося розібрати (%s)', $service, $reason),
            $previous,
        );
    }

    public function errorCode(): string
    {
        return $this->problemCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function problemExtensions(): array
    {
        return ['service' => $this->service];
    }
}
