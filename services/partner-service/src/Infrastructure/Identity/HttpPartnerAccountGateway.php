<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Domain\Identity\CreateAccountCommand;
use App\Domain\Identity\IdentityUnavailableException;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Продакшн-шлюз до identity-partner-service (DATA-35, AUTH-23…AUTH-28).
 *
 * КОНТРАКТ СУСІДА (джерело істини — InternalAccountController сусіднього
 * сервісу; жодного «покращення» на нашому боці):
 *
 *   POST   {base}/internal/v1/partner-accounts
 *          {"login","role","supplierId","passwordPlain"?,"driverProfileId"?,"active"?}
 *          201 {"accountId","login","role","contour","supplierId","driverId",
 *               "mustChangePassword","passwordGenerated","passwordPlain"?}
 *          409 code=PARTNER_ACCOUNT_LOGIN_TAKEN, 422 — некоректний логін/слабкий пароль;
 *   POST   {base}/internal/v1/partner-accounts/{accountId}/password/regenerate
 *          200 те саме тіло з новим "passwordPlain"; 404 — акаунта немає;
 *   POST   {base}/internal/v1/partner-accounts/suppliers/{supplierId}/suspend
 *          200 {"supplierId","deactivatedAccounts":int};
 *   DELETE {base}/internal/v1/partner-accounts/{accountId}/sessions
 *          204 без тіла.
 *
 * ТРАНСПОРТ. Базовий URL показує на внутрішній шлюз nginx, який слухає лише
 * 127.0.0.1:8081 і не публікується назовні. Службові маршрути не проходять
 * через auth_request і не мають заголовків ідентичності, тому клієнт нічого
 * не підписує і нічого не проксює.
 *
 * ДВІ МЕЖІ КОНТРАКТУ, які сусід сьогодні не покриває (свідомо не обходимо їх
 * «творчо», щоб не вигадувати неіснуючих маршрутів):
 *   1. окремого маршруту «увімкнути/вимкнути ОДИН акаунт» немає, тому
 *      деактивація виконується найсильнішим доступним засобом — завершенням
 *      усіх сесій акаунта (DELETE …/sessions), а прапорець `active` лишається
 *      за контуром ідентичності;
 *   2. зворотного до `suspend` маршруту («resume») теж немає, тому
 *      відновлення постачальника фіксується попередженням у журналі.
 * Обидва випадки гучно логуються — тиші тут бути не повинно.
 *
 * ПАРОЛІ. Ані пароль, ані тіла запитів/відповідей у журнал не потрапляють
 * (DATA-21, AUTH-61) — логуються лише accountId, supplierId і статус.
 */
final readonly class HttpPartnerAccountGateway implements PartnerAccountGateway
{
    /**
     * Таймаут одного виклику до сусіда. Виклик локальний і маленький, тому
     * 3 с — це вже аварія, а не повільна мережа: довше тримати користувача
     * в очікуванні модалки з паролем немає сенсу.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 3.0;

    /** Префікс службових маршрутів сусіда. */
    private const BASE_PATH = '/internal/v1/partner-accounts';

    private LoggerInterface $logger;

    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * SUP-DRV-03. Пароль генерує partner-service і передає його явно: саме цей
     * рядок уже пішов у модалку та в SMS, тож дати сусідові згенерувати свій
     * означало б розсинхронізувати те, що бачить водій, і те, що зберігає
     * контур ідентичності.
     *
     * Наслідок контракту: сусід ставить `mustChangePassword=true` лише коли
     * генерує пароль САМ (AUTH-24), тому при явному паролі прапорець буде
     * false, хоч команда і просить true. Ламати логін заради прапорця не
     * можна — примусову зміну пароля вмикати окремим маршрутом, коли він
     * зʼявиться в контракті сусіда.
     */
    public function createAccount(CreateAccountCommand $command): string
    {
        $outcome = 'обліковий запис водія не створено, тому водія не додано';

        $response = $this->exchange('POST', '', [
            'login' => $command->login,
            'role' => $command->role->value,
            'supplierId' => $command->supplierId,
            'passwordPlain' => $command->password,
            'driverProfileId' => $command->driverProfileId,
            'active' => true,
        ], $outcome);

        // 409 = unique {login:1}: той самий телефон уже належить іншому водієві
        // (крайовий випадок 3.3.2). Код лишаємо свій — такий самий, як у
        // InMemory-реалізації, щоб клієнт бачив однаковий `code` у будь-якому
        // оточенні, а не то ACCOUNT_LOGIN_DUPLICATE, то PARTNER_ACCOUNT_LOGIN_TAKEN.
        if (409 === $response['status']) {
            throw new ConflictException(
                \sprintf('Логін «%s» уже зареєстровано в партнерському контурі.', $command->login),
                'ACCOUNT_LOGIN_DUPLICATE',
            );
        }

        // 422 = сусід відхилив дані (формат логіна, політика паролів AUTH-21).
        // Це помилка вводу, а не аварія: показуємо текст сусіда українською.
        if (422 === $response['status']) {
            throw new ValidationException(
                $this->detail($response['payload'], 'Контур ідентичності відхилив дані облікового запису.'),
                'ACCOUNT_DATA_REJECTED',
            );
        }

        $payload = $this->requireSuccess($response, $outcome);
        $accountId = $payload['accountId'] ?? null;

        if (!\is_string($accountId) || '' === trim($accountId)) {
            throw IdentityUnavailableException::badResponse($outcome, 'у відповіді немає поля accountId');
        }

        $this->logger->info('identity-partner-service: створено обліковий запис', [
            'accountId' => $accountId,
            'supplierId' => $command->supplierId,
            'role' => $command->role->value,
        ]);

        return $accountId;
    }

    /**
     * SUP-DRV-04 / AUTH-25. Пароль генерує САМ контур ідентичності, тому
     * `$newPassword` тут лише «пропозиція»: назад повертається той пароль,
     * який реально записано в `partner_accounts`, і саме він іде в SMS.
     */
    public function resetPassword(string $accountId, string $newPassword): string
    {
        $outcome = 'пароль водія не перегенеровано';

        $response = $this->exchange(
            'POST',
            \sprintf('/%s/password/regenerate', self::segment($accountId)),
            null,
            $outcome,
        );

        if (404 === $response['status']) {
            throw new NotFoundException(
                \sprintf('Обліковий запис «%s» не знайдено в контурі ідентичності.', $accountId),
                'ACCOUNT_NOT_FOUND',
            );
        }

        $payload = $this->requireSuccess($response, $outcome);
        $password = $payload['passwordPlain'] ?? null;

        if (!\is_string($password) || '' === $password) {
            throw IdentityUnavailableException::badResponse($outcome, 'у відповіді немає нового пароля');
        }

        $this->logger->info('identity-partner-service: пароль перегенеровано', ['accountId' => $accountId]);

        return $password;
    }

    /**
     * SUP-DRV-05 і компенсація невдалого створення водія.
     *
     * Маршруту «вимкнути один акаунт» у сусіда немає, тож максимум доступного —
     * негайно завершити всі сесії акаунта: водій вилітає з driver-web у ту саму
     * секунду. Прапорець `partner_accounts.active` при цьому лишається
     * незмінним, тому попередження в журналі обовʼязкове.
     */
    public function setAccountActive(string $accountId, bool $active): void
    {
        if ($active) {
            // Вмикати нічого: сесії відновлюються звичайним логіном.
            $this->logger->warning(
                'identity-partner-service: активація одного акаунта не має службового маршруту — виклик пропущено',
                ['accountId' => $accountId],
            );

            return;
        }

        $outcome = 'сесії облікового запису не завершено';
        $response = $this->exchange('DELETE', \sprintf('/%s/sessions', self::segment($accountId)), null, $outcome);

        // Акаунта немає — мета «водій не має активних сесій» уже досягнута.
        if (404 === $response['status']) {
            $this->logger->warning(
                'identity-partner-service: обліковий запис для завершення сесій не знайдено',
                ['accountId' => $accountId],
            );

            return;
        }

        $this->requireSuccess($response, $outcome);

        $this->logger->warning(
            'identity-partner-service: сесії завершено, але прапорець active лишився без змін — службового маршруту деактивації акаунта в контракті сусіда немає',
            ['accountId' => $accountId],
        );
    }

    /**
     * SUP-02: масове блокування логінів постачальника.
     *
     * Зворотного маршруту («resume») контракт сусіда не має, тому відновлення
     * лишається ручною операцією на боці контуру ідентичності.
     */
    public function setSupplierAccountsActive(string $supplierId, bool $active): int
    {
        if ($active) {
            $this->logger->warning(
                'identity-partner-service: відновлення акаунтів постачальника не має службового маршруту — виклик пропущено',
                ['supplierId' => $supplierId],
            );

            return 0;
        }

        $outcome = 'акаунти постачальника не заблоковано';
        $payload = $this->requireSuccess(
            $this->exchange('POST', \sprintf('/suppliers/%s/suspend', self::segment($supplierId)), null, $outcome),
            $outcome,
        );

        $affected = $payload['deactivatedAccounts'] ?? null;

        if (!\is_int($affected)) {
            throw IdentityUnavailableException::badResponse($outcome, 'у відповіді немає лічильника deactivatedAccounts');
        }

        $this->logger->info('identity-partner-service: акаунти постачальника заблоковано', [
            'supplierId' => $supplierId,
            'deactivatedAccounts' => $affected,
        ]);

        return $affected;
    }

    /** Екранування сегмента шляху: id приходять ззовні і не мають ламати маршрут. */
    private static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Один виклик до сусіда: транспорт, таймаут і розбір тіла.
     *
     * Статус НЕ інтерпретується — це робота методів вище, бо 404 для сесій і
     * 404 для перегенерації пароля означають різне.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, payload: array<string, mixed>}
     *
     * @throws IdentityUnavailableException мережа, таймаут або нерозбірлива відповідь
     */
    private function exchange(string $method, string $path, ?array $body, string $outcome): array
    {
        $options = [
            'headers' => ['Accept' => 'application/json'],
            // timeout — простій зʼєднання, max_duration — жорстка стеля на
            // весь виклик: без другого повільний сусід тримав би форму
            // створення водія скільки завгодно.
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ];

        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/').self::BASE_PATH.$path, $options);
            $status = $response->getStatusCode();
            // false — не кидати виняток на 4xx/5xx: їх ми тлумачимо самі.
            $content = $response->getContent(false);
        } catch (HttpClientException $error) {
            // Таймаут, обрив, DNS, шлюз не піднято — усе сюди.
            throw IdentityUnavailableException::unreachable($outcome, $error->getMessage(), $error);
        }

        $decoded = '' === trim($content) ? [] : json_decode($content, true, 64);
        $payload = \is_array($decoded) ? $decoded : null;

        if (null === $payload) {
            // Тіло не розбирається. Якщо статус і так помилковий (HTML-сторінка
            // шлюзу, 502 від nginx) — причина саме в статусі, а не в JSON.
            if ($status < 200 || $status >= 300) {
                throw IdentityUnavailableException::rejected($outcome, $status);
            }

            throw IdentityUnavailableException::badResponse($outcome, 'некоректний JSON у відповіді');
        }

        /** @var array<string, mixed> $payload */
        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array{status: int, payload: array<string, mixed>} $response
     *
     * @return array<string, mixed>
     */
    private function requireSuccess(array $response, string $outcome): array
    {
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return $response['payload'];
        }

        throw IdentityUnavailableException::rejected($outcome, $response['status']);
    }

    /**
     * Текст помилки з problem+json сусіда: він уже українською і призначений
     * користувачу (AUTH-53), тому показуємо його як є.
     *
     * @param array<string, mixed> $payload
     */
    private function detail(array $payload, string $fallback): string
    {
        $detail = $payload['detail'] ?? null;

        return \is_string($detail) && '' !== trim($detail) ? $detail : $fallback;
    }
}
