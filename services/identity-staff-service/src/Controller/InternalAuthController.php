<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\TokenService;
use App\Domain\Identity\Contour;
use App\Domain\Identity\IdentitySnapshot;
use App\Domain\Identity\StaffUserRepository;
use App\Domain\Shared\DomainException;
use App\Http\BearerToken;
use App\Http\ProblemDetailsFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Службовий ендпоїнт перевірки токена для api-gateway (nginx `auth_request`).
 *
 * Схема: nginx на кожен запит до `/api/**` робить внутрішній підзапит сюди,
 * а з відповіді через `auth_request_set` знімає службові заголовки
 * ідентичності й додає їх до проксійованого запиту. Самі мікросервіси
 * (booking-service, store-service тощо) JWT не перевіряють — вони довіряють
 * шлюзу і читають ідентичність із цих заголовків.
 *
 * КОНТРАКТ (однаковий для identity-staff-service та identity-partner-service):
 *
 *   GET /internal/v1/auth/verify
 *   Authorization: Bearer <access-token>
 *
 *   204 No Content + заголовки:
 *     X-User-Id     — клейм `sub`;
 *     X-User-Role   — клейм `role` (однина, RBAC-04);
 *     X-Supplier-Id — постачальник; у staff-контурі ЗАВЖДИ порожній рядок;
 *     X-Store-Ids   — storeIds через кому; порожній для network-ролей (RBAC-16);
 *     X-Contour     — `staff`.
 *
 *   401 + application/problem+json, `code` = AUTH_TOKEN_INVALID — на БУДЬ-ЯКУ
 *   невдачу: підпис, `exp`, чужий контур (iss/aud/contour), denylist `jti`,
 *   деактивований або неіснуючий акаунт. Шлюзу причина не повідомляється
 *   (AUTH-53), вона лишається у `technicalReason` для логів.
 *
 * Префікс `/internal/v1/`, а НЕ `/api/`: ендпоїнт не публікується назовні
 * (map `$yms_service` у nginx маршрутизує лише `/api/`), доступ до нього —
 * тільки внутрішній підзапит шлюзу в межах кластера.
 *
 * ПРОДУКТИВНІСТЬ. Виклик відбувається на кожен запит до API, тому перевірки
 * впорядковані від найдешевших до найдорожчих: розбір заголовка → підпис і
 * клейми (лише CPU) → denylist `jti` (Redis) → і тільки після цього одне
 * читання з MongoDB за первинним ключем `_id` із проєкцією на чотири поля
 * (StaffUserRepository::findIdentityById). Невалідний токен до MongoDB
 * не доходить узагалі.
 */
final readonly class InternalAuthController
{
    /**
     * Імена заголовків — частина міжсервісного контракту; споживач —
     * ActorResolver кожного бізнес-сервісу.
     */
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string SUPPLIER_HEADER = 'X-Supplier-Id';
    public const string STORE_IDS_HEADER = 'X-Store-Ids';
    public const string CONTOUR_HEADER = 'X-Contour';

    public function __construct(
        private TokenService $tokens,
        private StaffUserRepository $users,
        private ProblemDetailsFactory $problems,
    ) {
    }

    #[Route('/internal/v1/auth/verify', name: 'internal_auth_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        try {
            $identity = $this->resolve($request);
        } catch (DomainException $exception) {
            // Контракт шлюзу: будь-яка невдача — рівно 401 AUTH_TOKEN_INVALID.
            // Власні коди сервісу (AUTH_TOKEN_EXPIRED, AUTH_ACCOUNT_DISABLED)
            // назовні не просочуються: шлюз не має розрізняти причини, а клієнт —
            // дізнаватися про стан чужого акаунта (AUTH-53).
            return $this->problems->fromDomainException(
                $exception instanceof InvalidTokenException
                    ? $exception
                    : new InvalidTokenException($exception->errorCode()),
                $request,
            );
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);

        $response->headers->set(self::USER_HEADER, $identity->userId);
        $response->headers->set(self::ROLE_HEADER, $identity->role->value);
        // У staff-контурі постачальника не існує — заголовок завжди порожній,
        // але присутній: шлюз має чим перезаписати значення, яке міг
        // підставити зовнішній клієнт.
        $response->headers->set(self::SUPPLIER_HEADER, '');
        $response->headers->set(self::STORE_IDS_HEADER, implode(',', $identity->scopedStoreIds()));
        $response->headers->set(self::CONTOUR_HEADER, Contour::Staff->value);

        $response->headers->set(
            ProblemDetailsFactory::REQUEST_ID_HEADER,
            ProblemDetailsFactory::requestId($request),
        );

        // Відповідь залежить від Authorization і не підлягає кешуванню
        // ані шлюзом, ані проміжними проксі.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @throws DomainException будь-яка невдача перевірки
     */
    private function resolve(Request $request): IdentitySnapshot
    {
        // Підпис ключем staff-контуру, exp, iss/aud/contour, тип токена
        // і denylist jti — усе всередині TokenService (AUTH-02, AUTH-03, AUTH-28).
        $claims = $this->tokens->verifyAccessToken(BearerToken::fromRequest($request));

        // Захист у глибину: TokenService звіряє клейм `contour`, а тут
        // додатково — що сама роль належить staff-контуру. Токен із
        // `contour: staff`, але роллю partner-контуру нікуди не проходить.
        if (Contour::Staff !== $claims->contour || Contour::Staff !== $claims->role->contour()) {
            throw new InvalidTokenException('роль токена не належить staff-контуру');
        }

        $identity = $this->users->findIdentityById($claims->subject);

        // AUTH-12/RBAC-26: деактивований або видалений акаунт не проходить
        // перевірку навіть із технічно валідним, ще не протермінованим токеном.
        if (null === $identity || !$identity->active) {
            throw new InvalidTokenException('акаунт не знайдено або деактивовано');
        }

        // RBAC-26: роль і скоуп беруться з поточного стану БД, а не з клеймів,
        // тому пониження прав діє негайно, а не «не пізніше TTL токена».
        return $identity;
    }
}
