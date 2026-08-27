<?php

declare(strict_types=1);

namespace App\Tests\Domain\Booking;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Exception\EditDeadlinePassedException;
use App\Domain\Booking\Exception\InvalidPlateNumberException;
use App\Domain\Booking\Exception\PalletsOutOfRangeException;
use App\Domain\Booking\Exception\VehicleTooHeavyException;
use App\Domain\Booking\VehicleSnapshot;
use App\Tests\Support\BookingFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Польові валідації бронювання: BOOK-01 (тоннаж), палети 1..33,
 * нормалізація держномера і дедлайн змін EDIT-02.
 */
#[CoversClass(VehicleSnapshot::class)]
final class BookingValidationTest extends TestCase
{
    /** BOOK-01: єдиний код помилки тоннажу — VEHICLE_TOO_HEAVY. */
    public function testVehicleHeavierThanStoreLimitIsRejected(): void
    {
        $vehicle = Scenario::vehicle(weightTons: 24.0);

        try {
            $vehicle->assertFitsStoreLimit(20.0);
            self::fail('Очікувалася відмова через перевищення тоннажу');
        } catch (VehicleTooHeavyException $error) {
            self::assertSame('VEHICLE_TOO_HEAVY', $error->errorCode());
            self::assertSame(422, $error->httpStatus());
            self::assertSame('Ця філія приймає авто до 20 т', $error->getMessage());
        }
    }

    public function testVehicleExactlyAtLimitIsAccepted(): void
    {
        Scenario::vehicle(weightTons: 20.0)->assertFitsStoreLimit(20.0);

        $this->addToAssertionCount(1);
    }

    public function testPlateNumberIsNormalizedToUpperCase(): void
    {
        $vehicle = new VehicleSnapshot(' aa 12 34 bb ', 5.0);

        self::assertSame('AA1234BB', $vehicle->plateNumber);
    }

    #[DataProvider('invalidPlates')]
    public function testInvalidPlateNumberIsRejected(string $plateNumber): void
    {
        $this->expectException(InvalidPlateNumberException::class);
        new VehicleSnapshot($plateNumber, 5.0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPlates(): iterable
    {
        yield 'закороткий' => ['AA1'];
        yield 'задовгий' => ['AA1234BB99999'];
    }

    #[DataProvider('invalidPalletCounts')]
    public function testPalletsOutOfRangeIsRejected(int $palletsCount): void
    {
        try {
            BookingFactory::scheduled(palletsCount: $palletsCount);
            self::fail('Очікувалася відмова через кількість палет');
        } catch (PalletsOutOfRangeException $error) {
            self::assertSame('PALLETS_OUT_OF_RANGE', $error->errorCode());
            self::assertSame(422, $error->httpStatus());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPalletCounts(): iterable
    {
        yield 'нуль' => [0];
        yield 'відʼємне' => [-3];
        yield 'понад 33' => [34];
    }

    #[DataProvider('validPalletCounts')]
    public function testPalletsWithinRangeAreAccepted(int $palletsCount): void
    {
        self::assertSame($palletsCount, BookingFactory::scheduled(palletsCount: $palletsCount)->palletsCount());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validPalletCounts(): iterable
    {
        yield 'мінімум' => [1];
        yield 'максимум' => [33];
    }

    /** EDIT-02: дедлайн змін для постачальника — 2 год до слоту. */
    public function testEditDeadlinePassedForSupplier(): void
    {
        $booking = BookingFactory::scheduled('2026-08-28 10:00');

        try {
            $booking->cancel(
                new Actor('pu-1', Role::SupplierAdmin, supplierId: Scenario::SUPPLIER_ID),
                Scenario::kyiv('2026-08-28 08:30'),
                2,
            );
            self::fail('Очікувалася відмова через дедлайн змін');
        } catch (EditDeadlinePassedException $error) {
            self::assertSame('EDIT_DEADLINE_PASSED', $error->errorCode());
            self::assertSame(422, $error->httpStatus());
            self::assertStringContainsString('за 2 год', $error->getMessage());
        }
    }

    /** EDIT-02: магазин і адмін дедлайном не обмежені. */
    public function testStoreIsNotLimitedByEditDeadline(): void
    {
        $booking = BookingFactory::scheduled('2026-08-28 10:00');
        $booking->cancel(
            new Actor('su-1', Role::StoreManager, storeId: Scenario::STORE_ID),
            Scenario::kyiv('2026-08-28 09:45'),
            2,
        );

        self::assertSame('cancelled', $booking->status()->value);
        self::assertSame('store', $booking->cancellation()?->by->value);
    }
}
