<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Internal\InternalSupplierBookingController;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Службовий маршрут GET /internal/v1/bookings/suppliers/{supplierId} — джерело
 * відповіді на питання SUP-06 «чи можна видалити постачальника».
 */
#[CoversClass(InternalSupplierBookingController::class)]
final class InternalSupplierBookingHttpTest extends TestCase
{
    /**
     * P-03: у щойно створеного постачальника бронювань немає — і сусід має
     * сказати саме це, а не «історія є».
     */
    public function testSupplierWithoutBookingsReportsFalse(): void
    {
        $scenario = new Scenario();

        $payload = $this->decode((new InternalSupplierBookingController($scenario->bookings))('sp-new'));

        self::assertSame('sp-new', $payload['supplierId']);
        self::assertFalse($payload['hasAnyBookings']);
    }

    public function testSupplierWithBookingReportsTrue(): void
    {
        $scenario = new Scenario();
        $scenario->book(supplierId: Scenario::SUPPLIER_ID);

        $payload = $this->decode(
            (new InternalSupplierBookingController($scenario->bookings))(Scenario::SUPPLIER_ID),
        );

        self::assertTrue($payload['hasAnyBookings']);
    }

    /** Бронювання одного постачальника не блокує видалення іншого. */
    public function testAnswerIsScopedToTheAskedSupplier(): void
    {
        $scenario = new Scenario();
        $scenario->book(supplierId: Scenario::SUPPLIER_ID);

        $payload = $this->decode(
            (new InternalSupplierBookingController($scenario->bookings))(Scenario::OTHER_SUPPLIER_ID),
        );

        self::assertFalse($payload['hasAnyBookings']);
    }

    /**
     * SUP-06 читає «будь-який статус»: скасоване бронювання — теж історія
     * поставок, і постачальника з ним видаляти не можна.
     */
    public function testCancelledBookingStillCountsAsHistory(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(supplierId: Scenario::SUPPLIER_ID);
        $scenario->lifecycle->cancel($scenario->networkAdmin(), $booking->id, $scenario->now());

        $payload = $this->decode(
            (new InternalSupplierBookingController($scenario->bookings))(Scenario::SUPPLIER_ID),
        );

        self::assertTrue($payload['hasAnyBookings']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
