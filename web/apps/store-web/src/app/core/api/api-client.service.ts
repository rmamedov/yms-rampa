import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, catchError, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';
import { toAppError } from './problem.util';

export type QueryParams = Record<
  string,
  string | number | boolean | readonly string[] | undefined | null
>;

/**
 * Тонка обгортка над HttpClient: базовий шлях /api/store/v1 і єдиний розбір
 * помилок RFC 7807 у AppError.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiBaseUrl;

  get<T>(path: string, params?: QueryParams): Observable<T> {
    return this.http
      .get<T>(this.url(path), { params: this.toHttpParams(params) })
      .pipe(catchError((error) => throwError(() => toAppError(error))));
  }

  post<T>(path: string, body?: unknown): Observable<T> {
    return this.http
      .post<T>(this.url(path), body ?? {})
      .pipe(catchError((error) => throwError(() => toAppError(error))));
  }

  patch<T>(path: string, body?: unknown): Observable<T> {
    return this.http
      .patch<T>(this.url(path), body ?? {})
      .pipe(catchError((error) => throwError(() => toAppError(error))));
  }

  delete<T>(path: string): Observable<T> {
    return this.http
      .delete<T>(this.url(path))
      .pipe(catchError((error) => throwError(() => toAppError(error))));
  }

  private url(path: string): string {
    return `${this.baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
  }

  private toHttpParams(params?: QueryParams): HttpParams {
    let httpParams = new HttpParams();
    if (!params) return httpParams;
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null) continue;
      if (Array.isArray(value)) {
        for (const item of value) {
          httpParams = httpParams.append(key, item);
        }
      } else {
        httpParams = httpParams.set(key, String(value));
      }
    }
    return httpParams;
  }
}
