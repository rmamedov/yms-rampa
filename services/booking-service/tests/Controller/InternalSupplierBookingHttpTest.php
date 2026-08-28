<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Internal\InternalSupplierBookingController;
use App\Domain\Booking\Exception\InvalidPlateNumberException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Службові маршрути `/internal/v1/bookings/suppliers/…` — джерело відповідей
 * на два питання partner-service:
 *   SUP-06     «чи можна видалити постачальника»;
 *   SUP-VEH-04 «чи можна видалити авто з довідника».
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

        $payload = $this->decode($this->controller($scenario)->supplier('sp-new'));

        self::assertSame('sp-new', $payload['supplierId']);
        self::assertFalse($payload['hasAnyBookings']);
    }

    public function testSupplierWithBookingReportsTrue(): void
    {
        $scenario = new Scenario();
        $scenario->book(supplierId: Scenario::SUPPLIER_ID);

        $payload = $this->decode($this->controller($scenario)->supplier(Scenario::SUPPLIER_ID));

        self::assertTrue($payload['hasAnyBookings']);
    }

    /** Бронювання одного постачальника не блокує видалення іншого. */
    public function testAnswerIsScopedToTheAskedSupplier(): void
    {
        $scenario = new Scenario();
        $scenario->book(supplierId: Scenario::SUPPLIER_ID);

        $payload = $this->decode($this->controller($scenario)->supplier(Scenario::OTHER_SUPPLIER_ID));

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

        $payload = $this->decode($this->controller($scenario)->supplier(Scenario::SUPPLIER_ID));

        self::assertTrue($payload['hasAnyBookings']);
    }

    // --- SUP-VEH-04: авто ---------------------------------------------------

    /**
     * ISSUE-22: щойно створене авто без жодного бронювання має видалятися.
     * Раніше це питання просто не було кому поставити, і partner-service
     * відмовляв завжди.
     */
    public function testVehicleWithoutBookingsReportsFalse(): void
    {
        $scenario = new Scenario();

        $payload = $this->decode(
            $this->controller($scenario)->vehicle(Scenario::SUPPLIER_ID, 'AA1234BB'),
        );

        self::assertSame(Scenario::SUPPLIER_ID, $payload['supplierId']);
        self::assertSame('AA1234BB', $payload['plateNumber']);
        self::assertFalse($payload['hasActiveBookings']);
    }

    public function testVehicleWithActiveBookingReportsTrue(): void
    {
        $scenario = new Scenario();
        $scenario->book(vehicle: Scenario::vehicle('BC5566CE'));

        $payload = $this->decode(
            $this->controller($scenario)->vehicle(Scenario::SUPPLIER_ID, 'BC5566CE'),
        );

        self::assertTrue($payload['hasActiveBookings']);
    }

    /**
     * Ключ — ПАРА «постачальник + номер» (DATA-18): однаковий номер у двох
     * перевізників — нормальна ситуація, і бронювання одного не має тримати
     * довідник іншого.
     */
    public function testAnswerIsScopedToSupplierAndPlate(): void
    {
        $scenario = new Scenario();
        $scenario->book(vehicle: Scenario::vehicle('BC5566CE'), supplierId: Scenario::SUPPLIER_ID);

        self::assertFalse($this->decode(
            $this->controller($scenario)->vehicle(Scenario::OTHER_SUPPLIER_ID, 'BC5566CE'),
        )['hasActiveBookings']);

        self::assertFalse($this->decode(
            $this->controller($scenario)->vehicle(Scenario::SUPPLIER_ID, 'AA0000AA'),
        )['hasActiveBookings']);
    }

    /**
     * Ключова відмінність від SUP-06: закрита поставка — це історія, вона
     * тримає власний снапшот авто і видаленню запису з довідника не заважає.
     */
    public function testClosedBookingDoesNotHoldTheVehicle(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book(vehicle: Scenario::vehicle('BC5566CE'));
        $scenario->lifecycle->cancel($scenario->networkAdmin(), $booking->id, $scenario->now());

        $payload = $this->decode(
            $this->controller($scenario)->vehicle(Scenario::SUPPLIER_ID, 'BC5566CE'),
        );

        self::assertFalse($payload['hasActiveBookings']);
    }

    /** Номер нормалізується так само, як у снапшоті бронювання (розділ 6.4). */
    public function testPlateIsNormalizedBeforeLookup(): void
    {
        $scenario = new Scenario();
        $scenario->book(vehicle: Scenario::vehicle('BC5566CE'));

        $payload = $this->decode(
            $this->controller($scenario)->vehicle(Scenario::SUPPLIER_ID, 'bc 5566 ce'),
        );

        self::assertSame('BC5566CE', $payload['plateNumber']);
        self::assertTrue($payload['hasActiveBookings']);
    }

    /**
     * Непридатний номер — помилка, а не «бронювань немає»: невдала перевірка
     * не має перетворюватися на дозвіл видалити авто.
     */
    public function testUnusablePlateIsRejected(): void
    {
        $scenario = new Scenario();

        $this->expectException(InvalidPlateNumberException::class);

        $this->controller($scenario)->vehicle(Scenario::SUPPLIER_ID, 'AB');
    }

    private function controller(Scenario $scenario): InternalSupplierBookingController
    {
        return new InternalSupplierBookingController($scenario->bookings);
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
