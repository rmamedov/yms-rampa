/**
 * Моделі трьох дій водія над бронюванням.
 *
 * ДЖЕРЕЛО ІСТИНИ — booking-service:
 *   POST  /api/driver/v1/bookings/{bookingId}/arrived
 *   POST  /api/driver/v1/bookings/{bookingId}/delay
 *   PATCH /api/driver/v1/bookings/{bookingId}
 * (App\Controller\Driver\BookingActionController +
 *  App\Application\Booking\DriverBookingService).
 *
 * Усі три маршрути віддають ПОВНЕ представлення бронювання
 * (BookingPresenter::toArray()), а не точку маршрутного листа, тож форма
 * відповіді дії НЕ дорівнює `RoutePoint` — вона ширша. Спільні поля
 * (`status`, `orderId`, `arrivedAt`, `delayed`) описані один раз у
 * route-sheet.model.ts: обидва контракти мусять читати їх однаково.
 */
import type { BookingStatus, DelayState } from './route-sheet.model';

/**
 * Довідник причин затримки — рівно значення backed enum
 * App\Domain\Booking\DelayReason. Значення УКРАЇНСЬКІ: у тіло запиту йде
 * саме такий рядок, латинських ключів бекенд не приймає
 * (RequestPayload::requiredEnum відхиляє все поза переліком з 422).
 */
export const DELAY_REASONS = [
  'затори',
  'поломка',
  'затримка на попередній точці',
  'інше',
] as const;

export type DelayReason = (typeof DELAY_REASONS)[number];

/** DelayReason::requiresComment() — коментар обовʼязковий лише для «інше». */
export const DELAY_REASON_REQUIRING_COMMENT: DelayReason = 'інше';

/**
 * Ключі словника для підписів причин. Самі значення довідника — це дані
 * бекенду (з малої літери), тож у інтерфейс вони не потрапляють напряму.
 */
export const DELAY_REASON_LABEL_KEYS: Readonly<Record<DelayReason, string>> = {
  'затори': 'delay.reason.traffic',
  'поломка': 'delay.reason.breakdown',
  'затримка на попередній точці': 'delay.reason.previousStop',
  'інше': 'delay.reason.other',
};

/** Тіло POST /bookings/{id}/delay. */
export interface DelayReport {
  readonly reason: DelayReason;
  /** Новий ETA, UTC ISO 8601. ETA в минулому бекенд відхиляє з 422. */
  readonly eta: string;
  /** Обовʼязковий для причини «інше», інакше — необовʼязковий. */
  readonly comment?: string | null;
}

/**
 * Зріз відповіді дії, який потрібен застосунку водія.
 *
 * Решту полів BookingPresenter (склад, слот, історія статусів) контур водія
 * не показує, тому вони свідомо не мапляться.
 */
export interface BookingActionResult {
  readonly bookingId: string;
  readonly status: BookingStatus;
  readonly orderId: string | null;
  /** UTC ISO 8601 або null, якщо прибуття ще не відмічене. */
  readonly arrivedAt: string | null;
  readonly delayed: DelayState;
}

/**
 * Сира відповідь маршрутів дій. Названо рівно як у BookingPresenter:
 * ідентифікатор там `id`, а не `bookingId`.
 */
export interface BookingResponse {
  readonly id: string;
  readonly status: BookingStatus;
  readonly orderId: string | null;
  readonly arrivedAt: string | null;
  readonly delayed?: Partial<DelayState> | null;
}
