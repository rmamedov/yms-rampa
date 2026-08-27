import {
  HttpErrorResponse,
  HttpEvent,
  HttpHandlerFn,
  HttpInterceptorFn,
  HttpRequest,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, Observable, switchMap, throwError } from 'rxjs';
import { AuthService } from '../auth/auth.service';

const AUTH_FREE_PATHS = ['/auth/login', '/auth/refresh'];

function isAuthFree(url: string): boolean {
  return AUTH_FREE_PATHS.some((path) => url.includes(path));
}

function withToken(
  request: HttpRequest<unknown>,
  token: string | null,
): HttpRequest<unknown> {
  if (!token) {
    return request;
  }
  return request.clone({
    setHeaders: { Authorization: `Bearer ${token}` },
  });
}

/**
 * Додає Authorization: Bearer <access token> і робить один refresh при 401 (RBAC-19/26).
 */
export const authInterceptor: HttpInterceptorFn = (
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> => {
  const auth = inject(AuthService);

  if (isAuthFree(request.url)) {
    return next(request);
  }

  return next(withToken(request, auth.accessToken())).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse) || error.status !== 401) {
        return throwError(() => error);
      }
      if (!auth.refreshToken()) {
        auth.logout();
        return throwError(() => error);
      }
      return auth.refresh().pipe(
        switchMap((tokens) => next(withToken(request, tokens.accessToken))),
        catchError((refreshError: unknown) => {
          auth.logout();
          return throwError(() => refreshError);
        }),
      );
    }),
  );
};
