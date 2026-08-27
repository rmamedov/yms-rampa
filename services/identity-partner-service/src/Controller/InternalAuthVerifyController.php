<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Auth\AccessTokenIntrospector;
use App\Domain\Exception\TokenInvalidException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Перевірка access-токена для api-gateway (nginx `auth_request`).
 *
 * Роль шлюзу виконує nginx: він робить внутрішній підзапит сюди і, отримавши
 * 204, підставляє повернуті службові заголовки в запит до мікросервісу.
 * Самі мікросервіси JWT не перевіряють — вони читають ідентичність із
 * заголовків (див. ActorResolver у booking-service).
 *
 * СПІЛЬНИЙ КОНТРАКТ обох identity-сервісів (staff і partner):
 *
 *   GET /internal/v1/auth/verify
 *   Authorization: Bearer <access-токен>
 *
 *   204 No Content, без тіла:
 *     X-User-Id           — sub токена (обліковий запис)
 *     X-User-Role         — клейм role (однина)
 *     X-Supplier-Id       — постачальник або порожній рядок
 *     X-Store-Ids         — магазини у скоупі через кому або порожній рядок
 *     X-Contour           — staff | partner
 *     X-Driver-Profile-Id — профіль водія або порожній рядок
 *
 * ЧОМУ ШОСТИЙ ЗАГОЛОВОК. У водія дві ідентичності: обліковий запис
 * (`partner_accounts`, він же `sub`) і бізнес-профіль (`partner_users` у
 * partner-service). Ідентифікатори різні, а booking-service зберігає в
 * `booking.driverId` саме ПРОФІЛЬ. Поки шлюз передавав лише `sub`, перевірка
 * «це бронювання мого маршрутного листа» не проходила ніколи — кожен водій
 * отримував 403 на «На місці». X-Driver-Profile-Id віддає сервісам ту саму
 * ідентичність, якою вони оперують.
 *
 * ЗАГОЛОВОК ПРИСУТНІЙ ЗАВЖДИ, навіть порожній: шлюз підставляє всі шість
 * заголовків примусово, і йому потрібне значення, яким можна ЗАТЕРТИ те, що
 * підклав клієнт. Відсутній заголовок затерти нічим — клієнтський пройшов би
 * наскрізь і дозволив би видати себе за чужого водія.
 *
 *   401 application/problem+json, code=AUTH_TOKEN_INVALID — будь-яка невдача:
 *     підпис, iss/aud, чужий контур, exp, denylist jti, деактивований акаунт,
 *     відсутній або некоректний заголовок Authorization.
 *
 * Ендпоїнт СЛУЖБОВИЙ: префікс `/internal/v1/`, а не `/api/`, тому назовні він
 * не публікується (nginx мапить на сервіси лише `/api/...`; `/internal/...`
 * доступний тільки в межах кластера).
 */
#[Route('/internal/v1/auth')]
final readonly class InternalAuthVerifyController
{
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string SUPPLIER_HEADER = 'X-Supplier-Id';
    public const string STORE_IDS_HEADER = 'X-Store-Ids';
    public const string CONTOUR_HEADER = 'X-Contour';
    public const string DRIVER_PROFILE_HEADER = 'X-Driver-Profile-Id';

    public function __construct(private AccessTokenIntrospector $introspector)
    {
    }

    #[Route('/verify', name: 'internal_auth_verify', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $identity = $this->introspector->introspect(self::bearerToken($request));

        // 204: тіла немає — шлюзу потрібні лише заголовки.
        return new Response('', Response::HTTP_NO_CONTENT, [
            self::USER_HEADER => $identity->userId,
            self::ROLE_HEADER => $identity->role->value,
            // Для partner-контуру supplierId заповнений завжди, зокрема водієві.
            self::SUPPLIER_HEADER => $identity->supplierId,
            // Магазини у скоуп партнера не входять — завжди порожній рядок.
            self::STORE_IDS_HEADER => $identity->storeIdsHeaderValue(),
            self::CONTOUR_HEADER => $identity->contour->value,
            // Порожній рядок для всіх ролей, крім driver, і для водія без
            // привʼязаного профілю — але сам заголовок є завжди.
            self::DRIVER_PROFILE_HEADER => $identity->driverProfileIdHeaderValue(),
        ]);
    }

    /**
     * Витягання токена з `Authorization: Bearer <token>`.
     *
     * Назва схеми за RFC 7235 регістронезалежна. Будь-яке відхилення від
     * формату — та сама помилка AUTH_TOKEN_INVALID, що й невалідний токен.
     *
     * @throws TokenInvalidException
     */
    private static function bearerToken(Request $request): string
    {
        $header = $request->headers->get('Authorization');

        if (null === $header || '' === trim($header)) {
            throw new TokenInvalidException('відсутній заголовок Authorization');
        }

        if (1 !== preg_match('/^Bearer[ \t]+(\S+)$/i', trim($header), $matches)) {
            throw new TokenInvalidException('очікується схема Bearer із одним токеном');
        }

        return $matches[1];
    }
}
