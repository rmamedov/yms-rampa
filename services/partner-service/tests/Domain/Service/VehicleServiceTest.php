<?php

declare(strict_types=1);

namespace App\Tests\Domain\Service;

use App\Domain\Service\VehicleService;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;
use App\Domain\Vehicle\Vehicle;
use App\Tests\Support\PartnerTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Довідник машин: SUP-VEH-01…SUP-VEH-04, SUP-BOOK-03, DATA-18, DATA-34.
 */
#[CoversClass(VehicleService::class)]
#[CoversClass(Vehicle::class)]
final class VehicleServiceTest extends TestCase
{
    private PartnerTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new PartnerTestEnvironment();
    }

    /**
     * SUP-VEH-02 / DATA-18 — головне правило: дублікат номера в межах
     * ОДНОГО постачальника відхиляється.
     */
    public function testPlateNumberMustBeUniqueWithinSupplier(): void
    {
        $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Авто з таким номером уже є у вашому довіднику.');

        $this->env->vehicleService->create('sp-1', 'AA1234BB', 8.0);
    }

    /**
     * SUP-VEH-02 — зворотний бік того самого правила: однаковий номер
     * у РІЗНИХ постачальників не конфлікт (перевізник обслуговує кількох).
     */
    public function testSamePlateNumberInDifferentSuppliersIsNotAConflict(): void
    {
        $first = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);
        $second = $this->env->vehicleService->create('sp-2', 'AA1234BB', 20.0);

        self::assertNotSame($first->id(), $second->id());
        self::assertSame('AA1234BB', $first->plateNumber());
        self::assertSame('AA1234BB', $second->plateNumber());
        self::assertCount(1, $this->env->vehicleService->list('sp-1'));
        self::assertCount(1, $this->env->vehicleService->list('sp-2'));
    }

    /**
     * Дублікат мусить ловитися ПІСЛЯ нормалізації, інакше правило
     * обходиться іншим регістром або пробілами.
     */
    public function testDuplicateIsDetectedAfterNormalization(): void
    {
        $this->env->vehicleService->create('sp-1', 'AA 1234 BB', 12.0);

        $this->expectException(ConflictException::class);

        $this->env->vehicleService->create('sp-1', 'aa-1234-bb', 12.0);
    }

    public function testRepositoryItselfRejectsDuplicateWhenDomainCheckIsBypassed(): void
    {
        // Емуляція compound unique index DATA-18 на рівні сховища.
        $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);

        $sneaky = new Vehicle(
            id: 'vh-hand-made',
            supplierId: 'sp-1',
            plateNumber: 'AA1234BB',
            weightTons: 5.0,
            brand: null,
            createdAt: $this->env->clock->now(),
        );

        $this->expectException(ConflictException::class);

        $this->env->vehicles->save($sneaky);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function outOfRangeWeights(): iterable
    {
        yield 'менше мінімуму' => [0.4];
        yield 'нуль' => [0.0];
        yield 'від\'ємне' => [-5.0];
        yield 'більше максимуму' => [40.1];
        yield 'абсурдно велике' => [400.0];
    }

    /**
     * DATA-34: довідник валідує лише глобальний діапазон 0.5–40 т.
     */
    #[DataProvider('outOfRangeWeights')]
    public function testWeightOutsideGlobalRangeIsRejected(float $weight): void
    {
        $this->expectException(ValidationException::class);

        $this->env->vehicleService->create('sp-1', 'AA1234BB', $weight);
    }

    public function testBoundaryWeightsAreAccepted(): void
    {
        $light = $this->env->vehicleService->create('sp-1', 'AA0001AA', 0.5);
        $heavy = $this->env->vehicleService->create('sp-1', 'AA0002AA', 40.0);

        self::assertSame(0.5, $light->weightTons());
        self::assertSame(40.0, $heavy->weightTons());
    }

    /**
     * SUP-VEH-04: авто з активними бронюваннями видалити не можна.
     */
    public function testVehicleWithActiveBookingsCannotBeDeleted(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);
        $this->env->bookings->registerVehicleActiveBooking('sp-1', $vehicle->plateNumber());

        try {
            $this->env->vehicleService->delete('sp-1', $vehicle->id());
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame('VEHICLE_HAS_ACTIVE_BOOKINGS', $e->errorCode());
            self::assertSame(409, $e->httpStatus());
        }
    }

    /**
     * ISSUE-22: авто, з яким немає жодного бронювання, МАЄ видалятися.
     * Раніше порт до booking-service був заглушкою «бронювання є завжди»,
     * тому довідник авто був невидаляним, а повідомлення — неправдивим.
     */
    public function testVehicleWithoutBookingsIsDeleted(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);

        $this->env->vehicleService->delete('sp-1', $vehicle->id());

        self::assertCount(0, $this->env->vehicleService->list('sp-1', includeInactive: true));
    }

    /**
     * DATA-18: питання ставиться за парою «постачальник + номер». Той самий
     * номер в іншого перевізника — чуже авто, і його бронювання не мають
     * тримати наш довідник.
     */
    public function testForeignSupplierBookingsDoNotBlockDeletion(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);
        $this->env->bookings->registerVehicleActiveBooking('sp-2', 'AA1234BB');

        $this->env->vehicleService->delete('sp-1', $vehicle->id());

        self::assertCount(0, $this->env->vehicleService->list('sp-1', includeInactive: true));
    }

    /**
     * SUP-VEH-04: замість видалення — деактивація; авто зникає з
     * випадаючого списку, але залишається в історії.
     */
    public function testDeactivatedVehicleDisappearsFromDropdownButRemainsInHistory(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);

        $this->env->vehicleService->deactivate('sp-1', $vehicle->id());

        self::assertCount(0, $this->env->vehicleService->list('sp-1'));
        self::assertCount(1, $this->env->vehicleService->list('sp-1', includeInactive: true));
        self::assertFalse($this->env->vehicleService->get('sp-1', $vehicle->id())->isActive());
    }

    public function testDeletedVehicleFreesItsPlateNumberForReuse(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);
        $this->env->vehicleService->delete('sp-1', $vehicle->id());

        // Після soft delete номер знову вільний у межах цього постачальника.
        $replacement = $this->env->vehicleService->create('sp-1', 'AA1234BB', 15.0);

        self::assertNotSame($vehicle->id(), $replacement->id());
        self::assertCount(1, $this->env->vehicleService->list('sp-1'));
    }

    /**
     * SUP-VEH-01: машина належить рівно одному постачальнику —
     * чуже авто недосяжне навіть за прямим ідентифікатором.
     */
    public function testVehicleOfAnotherSupplierIsNotAccessible(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);

        $this->expectException(NotFoundException::class);

        $this->env->vehicleService->get('sp-2', $vehicle->id());
    }

    /**
     * SUP-VEH-03: зміна вантажопідйомності — звичайна операція довідника;
     * знімок у бронюванні зберігає booking-service, тому тут перевіряємо
     * лише те, що нове значення застосувалося.
     */
    public function testWeightCanBeChangedWithinRange(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1234BB', 12.0);

        $updated = $this->env->vehicleService->changeWeight('sp-1', $vehicle->id(), 18.5);

        self::assertSame(18.5, $updated->weightTons());
    }

    public function testChangingPlateToOneUsedByAnotherOwnVehicleIsRejected(): void
    {
        $this->env->vehicleService->create('sp-1', 'AA1111AA', 12.0);
        $second = $this->env->vehicleService->create('sp-1', 'BB2222BB', 12.0);

        $this->expectException(ConflictException::class);

        $this->env->vehicleService->changePlateNumber('sp-1', $second->id(), 'aa 1111 aa');
    }

    public function testChangingPlateToItsOwnValueIsAllowed(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-1', 'AA1111AA', 12.0);

        $updated = $this->env->vehicleService->changePlateNumber('sp-1', $vehicle->id(), 'aa1111aa');

        self::assertSame('AA1111AA', $updated->plateNumber());
    }

    public function testBrandIsOptionalAndTrimmed(): void
    {
        $withBrand = $this->env->vehicleService->create('sp-1', 'AA1111AA', 12.0, '  Mercedes Actros ');
        $withoutBrand = $this->env->vehicleService->create('sp-1', 'BB2222BB', 12.0, '   ');

        self::assertSame('Mercedes Actros', $withBrand->brand());
        self::assertNull($withoutBrand->brand());
    }
}
