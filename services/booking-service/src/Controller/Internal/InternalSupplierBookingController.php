<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Domain\Booking\BookingRepository;
use App\Domain\Booking\VehicleSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Службова довідка про сліди постачальника в бронюваннях (SUP-06, SUP-VEH-04).
 *
 * КОНТРАКТ:
 *
 *   GET /internal/v1/bookings/suppliers/{supplierId}
 *   200 {"supplierId":"…","hasAnyBookings":bool}
 *
 *   GET /internal/v1/bookings/suppliers/{supplierId}/vehicles/{plateNumber}
 *   200 {"supplierId":"…","plateNumber":"…","hasActiveBookings":bool}
 *
 * Відповіді свідомо мінімальні — булеве «так/ні», а не перелік і не лічильник:
 * partner-service вирішує рівно одне питання «чи можна видалити запис», і
 * будь-яке зайве поле в тілі стало б обіцянкою, яку довелося б підтримувати.
 * 404 тут немає: постачальник (чи номер), якого booking-service ніколи не
 * бачив, — це коректна відповідь «false», а не помилка.
 *
 * ЧОМУ ДВА РІЗНІ ПИТАННЯ.
 *  - Постачальник: «будь-яке бронювання» означає БУДЬ-ЯКИЙ статус і будь-який
 *    тип — скасоване, no_show і позапланове прибуття теж лишаються історією
 *    поставок, тому теж блокують видалення (доступна лише деактивація, SUP-02).
 *  - Авто: рівно АКТИВНІ бронювання (booked | arrived | unloading). Закрита
 *    поставка носить у собі снапшот авто (DATA-13) і на видалення запису
 *    з довідника не впливає — інакше довідник ставав би невидаляним назавжди
 *    після першої ж поставки (ISSUE-22).
 *
 * ЧОМУ КЛЮЧ — ДЕРЖНОМЕР, А НЕ vehicleId. Бронювання зберігає снапшот авто,
 * а не посилання на довідник partner-service, тож маршруту за vehicleId
 * не існує й існувати не може без зміни моделі даних. Пара
 * «постачальник + номер» — єдиний спільний ключ обох сервісів (DATA-18:
 * номер унікальний у межах постачальника). Номер нормалізується тут-таки,
 * тими самими правилами, якими його нормалізує сам агрегат.
 *
 * Префікс `/internal/v1/`, а НЕ `/api/`: маршрути обслуговує лише внутрішній
 * шлюз nginx на 127.0.0.1:8081 (map `$yms_internal_service`, префікс
 * /internal/v1/bookings), назовні вони недосяжні. Через auth_request такі
 * запити не проходять і заголовків ідентичності не мають, тому ActorResolver
 * тут свідомо НЕ викликається — інакше міжсервісний виклик отримував би 403.
 */
#[Route('/internal/v1/bookings/suppliers')]
final readonly class InternalSupplierBookingController
{
    public function __construct(private BookingRepository $bookings)
    {
    }

    #[Route('/{supplierId}', name: 'internal_supplier_bookings', methods: ['GET'])]
    public function supplier(string $supplierId): JsonResponse
    {
        return new JsonResponse([
            'supplierId' => $supplierId,
            'hasAnyBookings' => $this->bookings->hasAnyBySupplier($supplierId),
        ]);
    }

    /**
     * SUP-VEH-04: чи тримають авто постачальника активні бронювання.
     *
     * Некоректний номер (коротший за 4 або довший за 12 символів) — це 422
     * VALIDATION_FAILED, а не «активних бронювань немає»: сусід не має права
     * вважати невдалу перевірку дозволом на видалення.
     */
    #[Route('/{supplierId}/vehicles/{plateNumber}', name: 'internal_supplier_vehicle_bookings', methods: ['GET'])]
    public function vehicle(string $supplierId, string $plateNumber): JsonResponse
    {
        $normalized = VehicleSnapshot::normalizePlate($plateNumber);

        return new JsonResponse([
            'supplierId' => $supplierId,
            'plateNumber' => $normalized,
            'hasActiveBookings' => $this->bookings->hasActiveBySupplierAndPlate($supplierId, $normalized),
        ]);
    }
}
