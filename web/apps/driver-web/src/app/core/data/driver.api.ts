import { Observable, catchError, forkJoin, map, of } from 'rxjs';
import type { AvailableDate, RouteSheetLoad } from '../models/route-sheet.model';
import type {
  BookingActionResult,
  DelayReport,
} from '../models/booking-action.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';

/**
 * Скільки днів уперед опитувати, щоб зібрати чипси дат (DRV-13).
 * Сьогодні + HORIZON днів.
 */
export const AVAILABLE_DATES_HORIZON_DAYS = 2;

/**
 * Контракт доступу до booking-service через api-gateway.
 *
 * Контур водія має один маршрут читання і три маршрути дій
 * (App\Controller\Driver\RouteSheetController і BookingActionController):
 *   GET   /api/driver/v1/route-sheet?date=YYYY-MM-DD
 *   POST  /api/driver/v1/bookings/{bookingId}/arrived
 *   POST  /api/driver/v1/bookings/{bookingId}/delay
 *   PATCH /api/driver/v1/bookings/{bookingId}
 * Авторизація в усіх чотирьох однакова — партнерський токен водія.
 *
 * Реалізації: HttpDriverApi (реальний бекенд) і MockDriverApi
 * (environment.useMocks) — обидві повертають однакову форму даних.
 */
export abstract class DriverApi {
  /**
   * GET /api/driver/v1/route-sheet?date=YYYY-MM-DD.
   *
   * Повертається не лише лист, а й його свіжість: без звʼязку service worker
   * віддає збережену копію тим самим 200, і застосунок мусить знати, що
   * показує саме її (DRV-33). `sheet: null` — на дату точок немає.
   */
  abstract routeSheet(date: string): Observable<RouteSheetLoad>;

  /**
   * POST /api/driver/v1/bookings/{bookingId}/arrived — «На місці»
   * (DRV + ST-01, booked → arrived).
   *
   * Дія ІДЕМПОТЕНТНА: відмітити прибуття можуть і водій, і магазин — хто
   * перший. Якщо бронювання вже `arrived`, DriverBookingService::markArrived
   * повертає поточний стан без другого переходу і без помилки.
   *
   * `occurredAt` — фактичний момент натискання (потрібен офлайн-черзі).
   * УВАГА: чинний контролер тіло запиту не читає і штампує СВІЙ час
   * (`Clock::now()`); поле надсилається наперед, на випадок коли бекенд
   * почне його приймати, і жодної помилки не спричиняє.
   */
  abstract markArrived(
    bookingId: string,
    occurredAt: string,
  ): Observable<BookingActionResult>;

  /**
   * POST /api/driver/v1/bookings/{bookingId}/delay — повідомлення про
   * затримку (DRV + DLY-01). Статус бронювання не змінюється.
   *
   * Бекенд відхиляє з 422: причину поза довідником, ETA в минулому
   * (`ETA має бути в майбутньому`) і причину «інше» без коментаря.
   */
  abstract reportDelay(
    bookingId: string,
    report: DelayReport,
  ): Observable<BookingActionResult>;

  /**
   * PATCH /api/driver/v1/bookings/{bookingId} — водій дописує або змінює
   * номер замовлення (розділ 6.4).
   *
   * У тілі має бути РІВНО `orderId`: будь-яке інше поле контролер
   * відхиляє з 403 ACCESS_DENIED. Дозволено лише до початку розвантаження —
   * далі 422 «Номер замовлення можна вказати лише до початку розвантаження».
   * `null` очищає номер.
   */
  abstract updateOrderId(
    bookingId: string,
    orderId: string | null,
  ): Observable<BookingActionResult>;

  /**
   * Перелік дат із поїздками (DRV-13).
   *
   * Окремого маршруту бекенд НЕ має, тому список збирається з наявного
   * `GET /route-sheet`: опитуємо сьогодні та найближчі дні горизонту.
   * Помилка на окремій даті не ламає весь перелік — така дата просто
   * не потрапляє у чипси.
   */
  availableDates(from: string = kyivDateKey()): Observable<readonly AvailableDate[]> {
    const dates = Array.from({ length: AVAILABLE_DATES_HORIZON_DAYS + 1 }, (_, i) =>
      addDaysToDateKey(from, i),
    );

    return forkJoin(
      dates.map((date) =>
        this.routeSheet(date).pipe(
          map((load) => load.sheet),
          catchError(() => of(null)),
        ),
      ),
    ).pipe(
      map((sheets) =>
        sheets.flatMap((sheet, index) =>
          sheet && sheet.points.length > 0
            ? [{ date: dates[index], pointCount: sheet.points.length }]
            : [],
        ),
      ),
    );
  }
}
