<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\Contour;
use App\Domain\Account\PartnerRole;
use App\Domain\Token\AccessTokenClaims;

/**
 * Ідентичність, яку api-gateway (nginx `auth_request`) підставляє у службові
 * заголовки запиту до мікросервісів.
 *
 * Мікросервіси (booking-service, store-service…) JWT не перевіряють: вони
 * читають готові заголовки — див. ActorResolver у booking-service. Тому набір
 * полів тут ЖОРСТКО збігається з контрактом обох identity-сервісів.
 *
 * Для partner-контуру:
 *  - supplierId завжди заповнений (у водія теж — це постачальник, до якого він
 *    прикріплений);
 *  - storeIds завжди порожній: магазини у скоуп партнера не входять;
 *  - contour завжди `partner`;
 *  - driverProfileId заповнений лише для водія з привʼязаним профілем.
 *
 * ДВІ ІДЕНТИЧНОСТІ ВОДІЯ. `userId` — це обліковий запис (`partner_accounts._id`,
 * клейм `sub`): ідентичність ДЛЯ ВХОДУ. Бізнес-ідентичність водія — це його
 * профіль (`partner_users._id` у partner-service), і саме його зберігають інші
 * сервіси: booking-service кладе його в `booking.driverId`. Ідентифікатори
 * РІЗНІ, тому порівняння `booking.driverId` з `userId` не збігається ніколи —
 * саме через це водій отримував 403 на «На місці». Тому знімок ідентичності
 * несе обидва: акаунт у `userId` і профіль у `driverProfileId`.
 */
final readonly class VerifiedIdentity
{
    /**
     * @param list<string> $storeIds        перелік магазинів у скоупі (для partner — завжди порожній)
     * @param ?string      $driverProfileId профіль водія (`partner_users._id`); null для не-водія
     *                                      і для водія без привʼязаного профілю
     */
    public function __construct(
        public string $userId,
        public PartnerRole $role,
        public string $supplierId,
        public array $storeIds,
        public Contour $contour,
        public ?string $driverProfileId,
    ) {
    }

    /**
     * Профіль береться з клейма `driverId`, який RsaJwtCodec кладе в токен із
     * `partner_accounts.driverProfileId` під час випуску. Зайвого читання
     * `partner_accounts` тут не потрібно: звʼязок акаунт → профіль ставиться
     * один раз при створенні акаунта і далі не змінюється, тож клейм не може
     * розійтися зі сховищем.
     */
    public static function fromClaims(AccessTokenClaims $claims): self
    {
        return new self(
            userId: $claims->subject,
            role: $claims->role,
            supplierId: $claims->supplierId,
            storeIds: [],
            contour: $claims->contour,
            driverProfileId: $claims->driverId,
        );
    }

    /** Значення заголовка X-Store-Ids: перелік через кому або порожній рядок. */
    public function storeIdsHeaderValue(): string
    {
        return implode(',', $this->storeIds);
    }

    /**
     * Значення заголовка X-Driver-Profile-Id: профіль водія або порожній рядок.
     *
     * Профіль має сенс ЛИШЕ для ролі driver, тому решті ролей віддається
     * порожній рядок навіть якби в акаунті випадково лежав ідентифікатор.
     */
    public function driverProfileIdHeaderValue(): string
    {
        if (!$this->role->isDriver()) {
            return '';
        }

        return $this->driverProfileId ?? '';
    }
}
