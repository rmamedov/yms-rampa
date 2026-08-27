<?php

declare(strict_types=1);

namespace App\Infrastructure\Booking;

use App\Domain\Booking\BookingQueryPort;
use App\Domain\Booking\BookingQueryUnavailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Продакшн-порт до booking-service (SUP-06).
 *
 * КОНТРАКТ СУСІДА (джерело істини — InternalSupplierBookingController
 * booking-service; жодного «покращення» на нашому боці):
 *
 *   GET {base}/internal/v1/bookings/suppliers/{supplierId}
 *       200 {"supplierId":"…","hasAnyBookings":bool}
 *
 * 404 контракт не передбачає: постачальник, якого booking-service ніколи не
 * бачив, — це `hasAnyBookings: false`. Тому будь-який статус поза 2xx тут
 * означає аварію, а не «бронювань немає».
 *
 * ТРАНСПОРТ. Базовий URL показує на внутрішній шлюз nginx, який слухає лише
 * 127.0.0.1:8081 і не публікується назовні (map `$yms_internal_service`,
 * префікс /internal/v1/bookings). Службові маршрути не проходять через
 * auth_request і не мають заголовків ідентичності, тому клієнт нічого не
 * підписує і нічого не проксює.
 *
 * ЩО РОБИТИ, КОЛИ СУСІД МОВЧИТЬ. Порт НЕ повертає `true` — це і був дефект,
 * через який довідник постачальників став невидаляним: домен отримував
 * «бронювання є» і показував 409 «постачальника з історією бронювань не можна
 * видалити» навіть щойно створеному запису. Замість вигаданої відповіді
 * кидається BookingQueryUnavailableException: видалення так само НЕ
 * відбувається (консервативно), але користувач бачить справжню причину —
 * недоступність сусіда — і знає, що спробу має сенс повторити.
 *
 * МЕЖА КОНТРАКТУ (SUP-VEH-04, свідомо не обходимо її «творчо»). Питання «чи є
 * в цього авто активні бронювання» booking-service поставити НЕМОЖЛИВО:
 * бронювання зберігає снапшот держномера (DATA-13), а не id авто з нашого
 * довідника, тож службового маршруту за vehicleId не існує. Тому перевірка
 * авто лишається fail-closed — видалення авто заблоковане, доступна лише
 * деактивація, — і кожен такий виклик гучно логується. Це відомий залишок,
 * а не спроба видати заглушку за реалізацію: щоб його зняти, booking-service
 * має віддати маршрут за парою «постачальник + держномер».
 */
final readonly class HttpBookingQueryPort implements BookingQueryPort
{
    /**
     * Таймаут одного виклику до сусіда. Виклик локальний і маленький, тому
     * 3 с — це вже аварія, а не повільна мережа: довше тримати адміністратора
     * в очікуванні відповіді на «видалити?» немає сенсу.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 3.0;

    /** Префікс службових маршрутів сусіда. */
    private const BASE_PATH = '/internal/v1/bookings';

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
     * SUP-06: чи існує хоч одне бронювання постачальника будь-якого статусу.
     *
     * @throws BookingQueryUnavailableException сусід недоступний або відповів
     *                                          не за контрактом
     */
    public function supplierHasAnyBookings(string $supplierId): bool
    {
        $outcome = 'постачальника не видалено';
        $payload = $this->getJson(
            \sprintf('/suppliers/%s', rawurlencode($supplierId)),
            $outcome,
        );

        $hasBookings = $payload['hasAnyBookings'] ?? null;

        if (!\is_bool($hasBookings)) {
            throw BookingQueryUnavailableException::badResponse(
                $outcome,
                'у відповіді немає булевого поля hasAnyBookings',
            );
        }

        return $hasBookings;
    }

    /**
     * SUP-VEH-04. Маршруту за vehicleId у сусіда немає (див. коментар класу),
     * тому відповідь консервативна і незмінна: авто вважається зайнятим.
     * Тиші тут бути не повинно — інакше заглушка знову стане непомітною.
     */
    public function vehicleHasActiveBookings(string $vehicleId): bool
    {
        $this->logger->warning(
            'booking-service: перевірка бронювань авто не має службового маршруту — видалення авто лишається заблокованим, доступна деактивація',
            ['vehicleId' => $vehicleId],
        );

        return true;
    }

    /**
     * Один виклик до сусіда: транспорт, таймаут і розбір тіла.
     *
     * @return array<string, mixed>
     *
     * @throws BookingQueryUnavailableException мережа, таймаут, статус поза 2xx
     *                                          або нерозбірлива відповідь
     */
    private function getJson(string $path, string $outcome): array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->baseUrl, '/').self::BASE_PATH.$path, [
                'headers' => ['Accept' => 'application/json'],
                // timeout — простій зʼєднання, max_duration — жорстка стеля на
                // весь виклик: без другого повільний сусід тримав би адмінку
                // в очікуванні скільки завгодно.
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            // false — не кидати виняток на 4xx/5xx: їх ми тлумачимо самі.
            $content = $response->getContent(false);
        } catch (HttpClientException $error) {
            // Таймаут, обрив, DNS, шлюз не піднято — усе сюди.
            throw BookingQueryUnavailableException::unreachable($outcome, $error->getMessage(), $error);
        }

        if ($status < 200 || $status >= 300) {
            throw BookingQueryUnavailableException::rejected($outcome, $status);
        }

        $decoded = json_decode($content, true, 32);

        if (!\is_array($decoded)) {
            throw BookingQueryUnavailableException::badResponse($outcome, 'некоректний JSON у відповіді');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
