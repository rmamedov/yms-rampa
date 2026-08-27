import { InjectionToken } from '@angular/core';
import { defer, delay, Observable, of, throwError } from 'rxjs';
import { environment } from '../../../../environments/environment';
import { Page, PageQuery, SortDirection } from '../../models';
import { ApiError, ProblemDetails } from '../../http/problem';

/** Затримка мок-відповідей; у тестах перекривається на 0. */
export const MOCK_LATENCY = new InjectionToken<number>('MOCK_LATENCY', {
  providedIn: 'root',
  factory: () => environment.mockLatencyMs,
});

export function respond<T>(factory: () => T, latency: number): Observable<T> {
  const source = defer(() => of(factory()));
  return latency > 0 ? source.pipe(delay(latency)) : source;
}

export function fail<T>(
  status: number,
  problem: ProblemDetails,
  latency: number,
): Observable<T> {
  const source = defer(() =>
    throwError(() => new ApiError(status, { status, ...problem })),
  );
  return latency > 0 ? source.pipe(delay(latency)) : source;
}

export type Comparator<T> = (a: T, b: T) => number;

export function compareValues(a: unknown, b: unknown): number {
  if (a === null || a === undefined) return b === null || b === undefined ? 0 : -1;
  if (b === null || b === undefined) return 1;
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  if (typeof a === 'boolean' && typeof b === 'boolean') {
    return Number(a) - Number(b);
  }
  return String(a).localeCompare(String(b), 'uk');
}

/** Серверне сортування (STL-05, UI-01) імітується в памʼяті мока. */
export function sortItems<T extends Record<string, unknown>>(
  items: readonly T[],
  sort: string | undefined,
  direction: SortDirection | undefined,
  fallback?: Comparator<T>,
): T[] {
  const sorted = [...items];
  if (!sort) {
    return fallback ? sorted.sort(fallback) : sorted;
  }
  const sign = direction === 'desc' ? -1 : 1;
  sorted.sort((a, b) => {
    const primary = compareValues(a[sort], b[sort]) * sign;
    if (primary !== 0) {
      return primary;
    }
    return fallback ? fallback(a, b) : 0;
  });
  return sorted;
}

/** Серверна пагінація (UI-01). */
export function paginate<T>(items: readonly T[], query: PageQuery): Page<T> {
  const pageSize = query.pageSize > 0 ? query.pageSize : 20;
  const pages = Math.max(1, Math.ceil(items.length / pageSize));
  const page = Math.min(Math.max(1, query.page), pages);
  const start = (page - 1) * pageSize;
  return {
    items: items.slice(start, start + pageSize),
    total: items.length,
    page,
    pageSize,
  };
}

export function normalize(value: string): string {
  return value.trim().toLowerCase();
}
