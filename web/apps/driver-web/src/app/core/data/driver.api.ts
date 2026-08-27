import { Observable, catchError, forkJoin, map, of } from 'rxjs';
import type { AvailableDate, DayRouteSheet } from '../models/route-sheet.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';

/**
 * Скільки днів уперед опитувати, щоб зібрати чипси дат (DRV-13).
 * Сьогодні + HORIZON днів.
 */
export const AVAILABLE_DATES_HORIZON_DAYS = 2;

/**
 * Контракт доступу до booking-service через api-gateway.
 *
 * У контурі водія бекенд має РІВНО ОДИН маршрут даних:
 *   GET /api/driver/v1/route-sheet?date=YYYY-MM-DD
 * Маршрутів для відмітки «На місці», введення orderId і повідомлення про
 * затримку в цьому контурі немає — див. заявки на бекенд у README задачі.
 *
 * Реалізації: HttpDriverApi (реальний бекенд) і MockDriverApi
 * (environment.useMocks) — обидві повертають однакову форму даних.
 */
export abstract class DriverApi {
  /**
   * GET /api/driver/v1/route-sheet?date=YYYY-MM-DD.
   * `null` — на дату точок немає (бекенд віддає `routeSheets: []`, 200).
   */
  abstract routeSheet(date: string): Observable<DayRouteSheet | null>;

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
        this.routeSheet(date).pipe(catchError(() => of(null))),
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
