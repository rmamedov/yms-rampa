import type {
  BookingActionResult,
  BookingResponse,
} from '../models/booking-action.model';
import { NO_DELAY, type DelayState } from '../models/route-sheet.model';

/**
 * Згортає відповідь маршруту дії (BookingPresenter::toArray()) у зріз,
 * потрібний застосунку водія.
 *
 * Ідентифікатор бронювання бекенд віддає полем `id` — саме тут воно
 * перейменовується на `bookingId`, як у проєкції маршрутного листа.
 * Поле `delayed` присутнє завжди, але мапер терпить його відсутність:
 * дефолт — «затримки немає».
 */
export function toBookingActionResult(
  response: BookingResponse,
): BookingActionResult {
  return {
    bookingId: response.id,
    status: response.status,
    orderId: response.orderId ?? null,
    arrivedAt: response.arrivedAt ?? null,
    delayed: toDelayState(response.delayed),
  };
}

function toDelayState(raw: Partial<DelayState> | null | undefined): DelayState {
  if (!raw) {
    return NO_DELAY;
  }
  return {
    flag: raw.flag === true,
    reason: raw.reason ?? null,
    eta: raw.eta ?? null,
  };
}
