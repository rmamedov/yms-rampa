<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Domain\Booking\BookingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Службова довідка про сліди постачальника в бронюваннях (SUP-06).
 *
 * КОНТРАКТ:
 *
 *   GET /internal/v1/bookings/suppliers/{supplierId}
 *   200 {"supplierId":"…","hasAnyBookings":bool}
 *
 * Відповідь свідомо мінімальна — булеве «так/ні», а не перелік і не лічильник:
 * partner-service вирішує рівно одне питання «чи можна видалити постачальника»,
 * і будь-яке зайве поле в тілі стало б обіцянкою, яку довелося б підтримувати.
 * 404 тут немає: постачальник, якого booking-service ніколи не бачив, — це
 * коректна відповідь `hasAnyBookings: false`, а не помилка.
 *
 * «Будь-яке бронювання» означає БУДЬ-ЯКИЙ статус і будь-який тип: скасоване,
 * no_show і позапланове прибуття теж лишаються історією поставок, тому теж
 * блокують видалення (доступна лише деактивація постачальника, SUP-02).
 *
 * Префікс `/internal/v1/`, а НЕ `/api/`: маршрут обслуговує лише внутрішній
 * шлюз nginx на 127.0.0.1:8081 (map `$yms_internal_service`, префікс
 * /internal/v1/bookings), назовні він недосяжний. Через auth_request такі
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
    public function __invoke(string $supplierId): JsonResponse
    {
        return new JsonResponse([
            'supplierId' => $supplierId,
            'hasAnyBookings' => $this->bookings->hasAnyBySupplier($supplierId),
        ]);
    }
}
