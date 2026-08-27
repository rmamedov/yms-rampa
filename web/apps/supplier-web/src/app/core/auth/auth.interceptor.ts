import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, switchMap, throwError } from 'rxjs';
import { AuthService } from './auth.service';

const SKIP_AUTH = ['/auth/login', '/auth/refresh'];

/**
 * Додає Authorization: Bearer <access token>, а при 401 виконує refresh
 * і повторює запит один раз. Невдалий refresh завершує сесію.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  if (SKIP_AUTH.some((path) => req.url.includes(path))) {
    return next(req);
  }
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.accessToken();
  const authorized = token
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authorized).pipe(
    catchError((error: unknown) => {
      const status = error instanceof HttpErrorResponse ? error.status : 0;
      if (status !== 401 || !token) {
        return throwError(() => error);
      }
      return auth.refreshTokens().pipe(
        switchMap((session) =>
          next(
            req.clone({
              setHeaders: { Authorization: `Bearer ${session.accessToken}` },
            }),
          ),
        ),
        catchError((refreshError: unknown) => {
          auth.expireSession(router.url);
          return throwError(() => refreshError);
        }),
      );
    }),
  );
};
