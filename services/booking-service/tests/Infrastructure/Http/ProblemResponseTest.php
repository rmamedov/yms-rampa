<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Role;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Booking\Exception\VehicleTimeConflictException;
use App\Domain\Booking\Exception\VehicleTooHeavyException;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Domain\Slot\DateOutOfHorizonException;
use App\Domain\Slot\SlotKey;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\ProblemExceptionListener;
use App\Infrastructure\Http\ProblemResponseFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Формат помилок RFC 7807 application/problem+json з розширеннями
 * `code` і `requestId`.
 */
#[CoversClass(ProblemResponseFactory::class)]
final class ProblemResponseTest extends TestCase
{
    public function testVehicleTooHeavyIsRenderedAsProblemJson(): void
    {
        $response = (new ProblemResponseFactory())
            ->fromThrowable(new VehicleTooHeavyException(20.0, 25.0), 'req-1');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $payload = $this->decode($response);

        self::assertSame('about:blank', $payload['type']);
        self::assertSame(422, $payload['status']);
        self::assertSame('VEHICLE_TOO_HEAVY', $payload['code']);
        self::assertSame('req-1', $payload['requestId']);
        self::assertSame('Ця філія приймає авто до 20 т', $payload['detail']);
        self::assertEqualsWithDelta(20.0, $payload['maxVehicleWeightTons'], 0.001);
        self::assertEqualsWithDelta(25.0, $payload['actualWeightTons'], 0.001);
    }

    /** GRID-03: доменний виняток слотового движка мапиться на 422. */
    public function testDateOutOfHorizonIsMapped(): void
    {
        $response = (new ProblemResponseFactory())
            ->fromThrowable(new DateOutOfHorizonException(14), 'req-2');

        $payload = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('DATE_OUT_OF_HORIZON', $payload['code']);
        self::assertSame(14, $payload['horizonDays']);
    }

    public function testSlotAlreadyBookedIsConflict(): void
    {
        $slotKey = new SlotKey(Scenario::STORE_ID, 'r1', Scenario::kyiv('2026-08-28 10:00'));
        $response = (new ProblemResponseFactory())
            ->fromThrowable(new SlotAlreadyBookedException($slotKey), 'req-3');

        $payload = $this->decode($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SLOT_ALREADY_BOOKED', $payload['code']);
        self::assertSame('r1', $payload['rampId']);
    }

    /** BOOK-04: конфлікт авто — попередження з деталями та підказкою обходу. */
    public function testVehicleTimeConflictCarriesWarningDetails(): void
    {
        $response = (new ProblemResponseFactory())->fromThrowable(
            new VehicleTimeConflictException('AA1234BB', [['bookingId' => 'bk-1']]),
            'req-4',
        );

        $payload = $this->decode($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('VEHICLE_TIME_CONFLICT', $payload['code']);
        self::assertTrue($payload['warning']);
        self::assertSame('bk-1', $payload['conflicts'][0]['bookingId']);
        self::assertStringContainsString('confirmConflict=true', $payload['resolution']);
    }

    /**
     * Недоступний сусід (store-service, partner-service) — це 503 з кодом і
     * назвою сервісу, а не 500 «внутрішня помилка»: клієнт має розуміти, що
     * запит має сенс повторити.
     */
    public function testUpstreamUnavailableIsRenderedAsServiceUnavailable(): void
    {
        $response = (new ProblemResponseFactory())->fromThrowable(
            UpstreamUnavailableException::partnerService('таймаут запиту'),
            'req-6',
        );

        $payload = $this->decode($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('UPSTREAM_UNAVAILABLE', $payload['code']);
        self::assertSame('partner-service', $payload['service']);
        self::assertSame('req-6', $payload['requestId']);
    }

    /** Відповідь сусіда не за контрактом — 502: повтор не допоможе. */
    public function testUpstreamBadResponseIsRenderedAsBadGateway(): void
    {
        $response = (new ProblemResponseFactory())->fromThrowable(
            UpstreamUnavailableException::badResponse('store-service', 'некоректний JSON'),
            'req-7',
        );

        $payload = $this->decode($response);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('UPSTREAM_BAD_RESPONSE', $payload['code']);
        self::assertSame('store-service', $payload['service']);
    }

    public function testUnexpectedErrorIsRenderedAsInternalWithoutLeakingDetails(): void
    {
        $response = (new ProblemResponseFactory())
            ->fromThrowable(new RuntimeException('SQL syntax error near password'), 'req-5');

        $payload = $this->decode($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('INTERNAL_ERROR', $payload['code']);
        self::assertStringNotContainsString('password', $payload['detail']);
    }

    public function testListenerConvertsExceptionOnApiRoutes(): void
    {
        $listener = new ProblemExceptionListener(new ProblemResponseFactory());
        $request = Request::create('/api/supplier/v1/bookings', 'POST');
        $request->headers->set('X-Request-Id', 'req-abc');

        $event = new ExceptionEvent(
            $this->kernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new AccessDeniedException(),
        );

        $listener($event);
        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('req-abc', $response->headers->get('X-Request-Id'));
        self::assertSame('ACCESS_DENIED', $this->decode($response)['code']);
    }

    public function testListenerIgnoresNonApiRoutes(): void
    {
        $listener = new ProblemExceptionListener(new ProblemResponseFactory());
        $event = new ExceptionEvent(
            $this->kernel(),
            Request::create('/health'),
            HttpKernelInterface::MAIN_REQUEST,
            new RuntimeException('boom'),
        );

        $listener($event);

        self::assertNull($event->getResponse());
    }

    /** Клейм ролі — `role` (однина), рівно одна роль на користувача. */
    public function testActorIsResolvedFromHeaders(): void
    {
        $request = Request::create('/api/supplier/v1/bookings');
        $request->headers->set(ActorResolver::USER_HEADER, 'pu-11');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'supplier_admin');
        $request->headers->set(ActorResolver::SUPPLIER_HEADER, 'sp-1');

        $actor = (new ActorResolver())->fromRequest($request);

        self::assertSame('pu-11', $actor->userId);
        self::assertSame(Role::SupplierAdmin, $actor->role);
        self::assertSame('sp-1', $actor->supplierId);
    }

    public function testUnknownRoleIsRejected(): void
    {
        $request = Request::create('/api/store/v1/bookings/walk-in');
        $request->headers->set(ActorResolver::USER_HEADER, 'su-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'store_admin');

        $this->expectException(AccessDeniedException::class);
        (new ActorResolver())->fromRequest($request);
    }

    public function testSupplierRoleWithoutSupplierHeaderIsRejected(): void
    {
        $request = Request::create('/api/supplier/v1/bookings');
        $request->headers->set(ActorResolver::USER_HEADER, 'pu-11');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'supplier_operator');

        $this->expectException(AccessDeniedException::class);
        (new ActorResolver())->fromRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
