<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Shared\DomainException;

/**
 * identity-partner-service не відповів або відповів не за контрактом.
 *
 * Це НЕ помилка бізнес-правила: домен просто не отримав підтвердження, що
 * облікові дані створено (змінено, заблоковано). Окремий доменний виняток
 * потрібен, щоб користувач побачив зрозумілий problem+json з полем `code`
 * і текстом українською, а не 500 зі стектрейсом (розділ 3.7).
 *
 * Два випадки навмисно розрізняються:
 *   - 503 IDENTITY_UNAVAILABLE   — мережа, таймаут, 5xx або будь-який інший
 *     несподіваний статус: повторити спробу має сенс;
 *   - 502 IDENTITY_BAD_RESPONSE  — відповідь прийшла, але це не той контракт
 *     (не JSON, немає обовʼязкових полів): повтор не допоможе, потрібне
 *     втручання інженера.
 *
 * ВАЖЛИВО для сценарію SUP-DRV-03: `createAccount` викликається ДО запису
 * профілю водія (`partner_users`), тому цей виняток завжди означає, що ані
 * акаунта, ані профілю не створено — «осиротілого» профілю не виникає, і
 * текст повідомлення прямо про це говорить.
 */
final class IdentityUnavailableException extends DomainException
{
    public const ERROR_CODE = 'IDENTITY_UNAVAILABLE';
    public const BAD_RESPONSE_CODE = 'IDENTITY_BAD_RESPONSE';

    private function __construct(
        string $message,
        string $errorCode,
        private readonly int $status,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 'Сервіс облікових записів недоступний', $previous);
    }

    /**
     * Сусід недосяжний: таймаут, обрив, відмова зʼєднання, DNS.
     *
     * @param string $outcome що саме НЕ сталося, у формі результату:
     *                        «обліковий запис водія не створено»
     */
    public static function unreachable(string $outcome, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf(
                'Сервіс облікових записів тимчасово недоступний: %s (%s). Спробуйте ще раз за кілька хвилин.',
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
                'Сервіс облікових записів відповів неочікувано: %s (%s). Зверніться до підтримки.',
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
