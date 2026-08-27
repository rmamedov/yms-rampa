import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, map, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { RouteSheetApi } from '../api/contracts';
import type {
  RouteSheet,
  RouteSheetSummary,
  UpcomingDelivery,
} from '../models/models';
import { addDays, diffDays, kyivDateIso } from '../util/kyiv-time';

/**
 * Бекенд віддає маршрутний лист лише на КОНКРЕТНУ дату
 * (`GET /route-sheets?date=YYYY-MM-DD`); ні списку листів, ні списку
 * активних бронювань постачальника в контурі немає (див. problems).
 *
 * Тому і список листів, і «найближчі поставки» на головній збираються
 * тут — із листів за обмеженим діапазоном дат.
 */

export const SHEETS_PAST_DAYS = 7;
export const SHEETS_FUTURE_DAYS = 14;
export const UPCOMING_DAYS = 7;

const ACTIVE_STATUSES = new Set(['booked', 'arrived', 'unloading']);

@Injectable({ providedIn: 'root' })
export class RouteSheetsService {
  private readonly api = inject(RouteSheetApi);

  /** Зведення листів за вікном дат навколо сьогодні. */
  summaries(
    today: string = kyivDateIso(new Date()),
    pastDays: number = SHEETS_PAST_DAYS,
    futureDays: number = SHEETS_FUTURE_DAYS,
  ): Observable<RouteSheetSummary[]> {
    const dates = rangeOf(addDays(today, -pastDays), pastDays + futureDays + 1);
    return this.sheets(dates).pipe(
      map((sheets) =>
        sheets
          .filter((sheet) => sheet.points.length > 0)
          .map((sheet) => summaryOf(sheet, today)),
      ),
    );
  }

  /** SUP-HOME-01: найближчі активні поставки з листів на найближчі дні. */
  upcoming(
    limit: number,
    today: string = kyivDateIso(new Date()),
    days: number = UPCOMING_DAYS,
    now: Date = new Date(),
  ): Observable<UpcomingDelivery[]> {
    return this.sheets(rangeOf(today, days)).pipe(
      map((sheets) => {
        const points: UpcomingDelivery[] = [];
        for (const sheet of sheets) {
          for (const point of sheet.points) {
            if (
              ACTIVE_STATUSES.has(point.status) &&
              new Date(point.slotStart).getTime() >= now.getTime()
            ) {
              points.push({ ...point, date: sheet.date });
            }
          }
        }
        return points
          .sort((a, b) => a.slotStart.localeCompare(b.slotStart))
          .slice(0, limit);
      }),
    );
  }

  /**
   * Лист на дату, якої ще немає, бекенд створює порожнім — жодна з дат
   * діапазону не повинна валити весь запит.
   */
  private sheets(dates: readonly string[]): Observable<RouteSheet[]> {
    if (dates.length === 0) {
      return of([]);
    }
    return forkJoin(
      dates.map((date) =>
        this.api.detail(date).pipe(catchError(() => of(emptySheet(date)))),
      ),
    );
  }
}

function rangeOf(from: string, days: number): string[] {
  return Array.from({ length: days }, (_, i) => addDays(from, i));
}

function emptySheet(date: string): RouteSheet {
  return {
    routeSheetId: '',
    supplierId: '',
    supplierName: null,
    date,
    printVersion: 0,
    points: [],
  };
}

export function summaryOf(sheet: RouteSheet, today: string): RouteSheetSummary {
  const drivers = new Set(sheet.points.map((point) => point.driverId));
  return {
    date: sheet.date,
    pointsCount: sheet.points.length,
    driverId:
      drivers.size === 1 ? ([...drivers][0] ?? null) : null,
    archived: diffDays(today, sheet.date) < 0,
  };
}
