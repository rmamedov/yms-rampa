<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Booking\BookingQueryPort;
use App\Domain\Shared\Clock;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\IdGenerator;
use App\Domain\Shared\NotFoundException;
use App\Domain\Vehicle\PlateNumberNormalizer;
use App\Domain\Vehicle\Vehicle;
use App\Domain\Vehicle\VehicleRepository;

/**
 * Довідник машин постачальника (SUP-VEH-01…SUP-VEH-04, SUP-BOOK-03).
 *
 * Ключове правило (SUP-VEH-02, DATA-18): держномер унікальний У МЕЖАХ
 * постачальника. Два різні постачальники можуть мати авто з однаковим
 * номером — це нормальна ситуація, коли перевізник обслуговує кількох
 * клієнтів, і вона НЕ вважається конфліктом.
 */
final readonly class VehicleService
{
    public function __construct(
        private VehicleRepository $vehicles,
        private BookingQueryPort $bookings,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /**
     * SUP-BOOK-03: створення авто. Номер нормалізується ДО перевірки дубліката,
     * тому «aa1234bb», «AA 1234 BB» і «AA-1234-BB» — один і той самий номер.
     * DATA-34: вантажопідйомність валідується лише глобальним діапазоном 0.5–40 т.
     */
    public function create(
        string $supplierId,
        string $plateNumber,
        float $weightTons,
        ?string $brand = null,
    ): Vehicle {
        $now = $this->clock->now();

        $vehicle = new Vehicle(
            id: $this->ids->generate(),
            supplierId: $supplierId,
            plateNumber: $plateNumber,
            weightTons: $weightTons,
            brand: $brand,
            createdAt: $now,
        );

        $this->assertPlateFreeForSupplier($supplierId, $vehicle->plateNumber(), null);
        $this->vehicles->save($vehicle);

        return $vehicle;
    }

    /**
     * Читання авто з перевіркою належності постачальнику: чуже авто
     * не відрізняється від неіснуючого (404, без розкриття факту існування).
     */
    public function get(string $supplierId, string $vehicleId): Vehicle
    {
        $vehicle = $this->vehicles->findById($vehicleId);

        if (null === $vehicle || $vehicle->supplierId() !== $supplierId) {
            throw new NotFoundException(
                \sprintf('Авто «%s» не знайдено у вашому довіднику.', $vehicleId),
                'VEHICLE_NOT_FOUND',
            );
        }

        return $vehicle;
    }

    /**
     * @return list<Vehicle>
     */
    public function list(string $supplierId, bool $includeInactive = false): array
    {
        return $this->vehicles->listBySupplier($supplierId, $includeInactive);
    }

    public function changePlateNumber(string $supplierId, string $vehicleId, string $plateNumber): Vehicle
    {
        $vehicle = $this->get($supplierId, $vehicleId);
        $normalized = PlateNumberNormalizer::normalize($plateNumber);

        $this->assertPlateFreeForSupplier($supplierId, $normalized, $vehicle->id());
        $vehicle->changePlateNumber($normalized, $this->clock->now());
        $this->vehicles->save($vehicle);

        return $vehicle;
    }

    /**
     * SUP-VEH-03: зміна вантажопідйомності діє лише на майбутні бронювання —
     * у створених бронюваннях зберігається снапшот параметрів авто (DATA-06).
     */
    public function changeWeight(string $supplierId, string $vehicleId, float $weightTons): Vehicle
    {
        $vehicle = $this->get($supplierId, $vehicleId);
        $vehicle->changeWeight($weightTons, $this->clock->now());
        $this->vehicles->save($vehicle);

        return $vehicle;
    }

    public function changeBrand(string $supplierId, string $vehicleId, ?string $brand): Vehicle
    {
        $vehicle = $this->get($supplierId, $vehicleId);
        $vehicle->changeBrand($brand, $this->clock->now());
        $this->vehicles->save($vehicle);

        return $vehicle;
    }

    /**
     * SUP-VEH-04: деактивація — авто зникає з випадаючого списку панелі
     * бронювання, історія зберігається.
     */
    public function deactivate(string $supplierId, string $vehicleId): Vehicle
    {
        $vehicle = $this->get($supplierId, $vehicleId);

        if ($vehicle->deactivate($this->clock->now())) {
            $this->vehicles->save($vehicle);
        }

        return $vehicle;
    }

    public function activate(string $supplierId, string $vehicleId): Vehicle
    {
        $vehicle = $this->get($supplierId, $vehicleId);

        if ($vehicle->activate($this->clock->now())) {
            $this->vehicles->save($vehicle);
        }

        return $vehicle;
    }

    /**
     * SUP-VEH-04: видалення авто, прив'язаного до активних бронювань,
     * заборонене — замість нього пропонується деактивація.
     * Видалення виконується як soft delete (DATA-03).
     */
    public function delete(string $supplierId, string $vehicleId): void
    {
        $vehicle = $this->get($supplierId, $vehicleId);

        if ($this->bookings->vehicleHasActiveBookings($vehicle->id())) {
            throw new ConflictException(
                'Авто прив\'язане до активних бронювань. Деактивуйте його замість видалення.',
                'VEHICLE_HAS_ACTIVE_BOOKINGS',
            );
        }

        $vehicle->archive($this->clock->now());
        $this->vehicles->save($vehicle);
    }

    /**
     * SUP-VEH-02 / DATA-18 — єдине місце, де перевіряється унікальність номера.
     * Пошук свідомо обмежений постачальником: глобальної унікальності немає.
     */
    private function assertPlateFreeForSupplier(string $supplierId, string $plateNumber, ?string $exceptId): void
    {
        $existing = $this->vehicles->findBySupplierAndPlate($supplierId, $plateNumber);

        if (null !== $existing && $existing->id() !== $exceptId) {
            throw new ConflictException(
                'Авто з таким номером уже є у вашому довіднику.',
                'VEHICLE_PLATE_DUPLICATE',
            );
        }
    }
}
