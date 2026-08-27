<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\TokenService;
use App\Domain\Identity\Contour;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ідентичність запиту для захищених маршрутів `/api/admin/v1/**`.
 *
 * Джерело — те саме, що й в інших мікросервісів: службові заголовки, які
 * api-gateway виставляє після підзапиту `GET /internal/v1/auth/verify`
 * (див. InternalAuthController і snippets/yms-api.conf; nginx виставляє їх
 * через fastcgi_param ЗАВЖДИ, тому підробити їх ззовні не можна).
 *
 * Відмінність від інших сервісів одна: identity-staff-service володіє
 * колекцією `staff_users`, тому із заголовка береться ЛИШЕ ідентифікатор,
 * а роль і скоуп читаються з бази (RBAC-26: пониження прав діє негайно,
 * а не «не пізніше TTL токена»).
 *
 * Запасний шлях — власний access-токен у заголовку Authorization: він
 * потрібен, коли сервіс викликають без шлюзу (локальна розробка, службові
 * перевірки). Токен перевіряється тим самим TokenService, що й на логіні,
 * тож послаблення тут немає.
 */
final readonly class ActorResolver
{
    public const string USER_HEADER = 'X-User-Id';
    public const string ROLE_HEADER = 'X-User-Role';
    public const string CONTOUR_HEADER = 'X-Contour';

    public function __construct(
        private StaffUserRepository $users,
        private TokenService $tokens,
    ) {
    }

    /**
     * @throws InvalidTokenException якщо ідентичність відсутня, неузгоджена
     *                              або акаунт деактивовано (AUTH-12)
     */
    public function staff(Request $request): StaffUser
    {
        $actor = $this->users->findById($this->userId($request));

        // AUTH-12/RBAC-26: акаунт міг бути деактивований уже після того,
        // як шлюз перевірив токен.
        if (!$actor instanceof StaffUser || !$actor->isActive()) {
            throw new InvalidTokenException('акаунт не знайдено або деактивовано');
        }

        // Захист у глибину: staff-контур обслуговує лише свої ролі (RBAC-19).
        if (Contour::Staff !== $actor->role()->contour()) {
            throw new InvalidTokenException('роль актора не належить staff-контуру');
        }

        return $actor;
    }

    private function userId(Request $request): string
    {
        $userId = trim((string) $request->headers->get(self::USER_HEADER, ''));

        if ('' === $userId) {
            // Без шлюзу — власний access-токен staff-контуру.
            return $this->tokens->verifyAccessToken(BearerToken::fromRequest($request))->subject;
        }

        $this->assertHeadersConsistent($request);

        return $userId;
    }

    /**
     * Розбіжність між `X-Contour` і `X-User-Role` — ознака запиту в обхід
     * шлюзу з підробленими заголовками; такий запит відхиляється, а не
     * «виправляється» на користь одного зі значень.
     */
    private function assertHeadersConsistent(Request $request): void
    {
        $roleValue = trim((string) $request->headers->get(self::ROLE_HEADER, ''));

        if ('' !== $roleValue) {
            $role = Role::tryFrom($roleValue);

            if (!$role instanceof Role || Contour::Staff !== $role->contour()) {
                throw new InvalidTokenException('невідома роль у заголовку ідентичності');
            }
        }

        $contour = trim((string) $request->headers->get(self::CONTOUR_HEADER, ''));

        if ('' !== $contour && Contour::Staff->value !== $contour) {
            throw new InvalidTokenException('контур заголовка не збігається зі staff');
        }
    }
}
