import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, Observable, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';
import { parseProblem } from './problem';

export type QueryParams = Readonly<
  Record<string, string | number | boolean | readonly string[] | null | undefined>
>;

/**
 * Списки бекенд приймає і як повторювані параметри, і як перелік через кому
 * (AnalyticsQueryFactory::list, StoreCatalogService::listParam) — шлемо через кому.
 */
function toHttpParams(params?: QueryParams): HttpParams {
  let httpParams = new HttpParams();
  if (!params) {
    return httpParams;
  }
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') {
      continue;
    }
    if (Array.isArray(value)) {
      if (value.length > 0) {
        httpParams = httpParams.set(key, value.join(','));
      }
      continue;
    }
    httpParams = httpParams.set(key, String(value));
  }
  return httpParams;
}

/**
 * Обгортка над HttpClient для контуру /api/admin/v1/...
 * Усі помилки нормалізуються в ApiError (RFC 7807 application/problem+json).
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiBaseUrl;

  private url(path: string): string {
    return `${this.base}${path.startsWith('/') ? path : `/${path}`}`;
  }

  get<T>(path: string, params?: QueryParams): Observable<T> {
    return this.http
      .get<T>(this.url(path), { params: toHttpParams(params) })
      .pipe(catchError((e: unknown) => throwError(() => parseProblem(e))));
  }

  /** ANL-11: /analytics/export.csv віддає text/csv, а не JSON. */
  getText(path: string, params?: QueryParams): Observable<string> {
    return this.http
      .get(this.url(path), { params: toHttpParams(params), responseType: 'text' })
      .pipe(catchError((e: unknown) => throwError(() => parseProblem(e))));
  }

  post<T>(path: string, body?: unknown): Observable<T> {
    return this.http
      .post<T>(this.url(path), body ?? {})
      .pipe(catchError((e: unknown) => throwError(() => parseProblem(e))));
  }

  patch<T>(path: string, body?: unknown): Observable<T> {
    return this.http
      .patch<T>(this.url(path), body ?? {})
      .pipe(catchError((e: unknown) => throwError(() => parseProblem(e))));
  }

  put<T>(path: string, body?: unknown): Observable<T> {
    return this.http
      .put<T>(this.url(path), body ?? {})
      .pipe(catchError((e: unknown) => throwError(() => parseProblem(e))));
  }

  delete<T>(path: string): Observable<T> {
    return this.http
      .delete<T>(this.url(path))
      .pipe(catchError((e: unknown) => throwError(() => parseProblem(e))));
  }
}
