import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiProblemError, toProblem } from './problem';

export type QueryParams = Record<string, string | number | boolean | undefined>;

/**
 * Тонка обгортка над HttpClient: базовий шлях /api/supplier/v1
 * і нормалізація помилок у RFC 7807 problem-документ.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  readonly baseUrl = `${environment.apiBaseUrl}/supplier/v1`;

  get<T>(path: string, params?: QueryParams): Observable<T> {
    return this.wrap(
      this.http.get<T>(this.url(path), { params: toHttpParams(params) }),
    );
  }

  post<T>(path: string, body?: unknown): Observable<T> {
    return this.wrap(this.http.post<T>(this.url(path), body ?? {}));
  }

  put<T>(path: string, body?: unknown): Observable<T> {
    return this.wrap(this.http.put<T>(this.url(path), body ?? {}));
  }

  patch<T>(path: string, body?: unknown): Observable<T> {
    return this.wrap(this.http.patch<T>(this.url(path), body ?? {}));
  }

  delete<T>(path: string): Observable<T> {
    return this.wrap(this.http.delete<T>(this.url(path)));
  }

  private url(path: string): string {
    return `${this.baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
  }

  private wrap<T>(source: Observable<T>): Observable<T> {
    return source.pipe(
      catchError((error: unknown) =>
        throwError(() => new ApiProblemError(toProblem(error))),
      ),
    );
  }
}

function toHttpParams(params?: QueryParams): HttpParams {
  let httpParams = new HttpParams();
  if (!params) {
    return httpParams;
  }
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') {
      httpParams = httpParams.set(key, String(value));
    }
  }
  return httpParams;
}
