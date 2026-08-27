import { HttpErrorResponse, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, switchMap, throwError } from 'rxjs';
import { AuthService } from '../auth/auth.service';
import { SKIP_AUTH } from './auth.context';
import { ApiProblemError, toProblem } from '../models/problem.model';

/**
 * Додає Authorization: Bearer <access token> та виконує безшовний refresh при 401
 * (AUTH-44). Помилки бекенду перетворює на ApiProblemError за RFC 7807 (API-02).
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const skipAuth = req.context.get(SKIP_AUTH);

  const withToken = (request: HttpRequest<unknown>, token: string | null) =>
    token && !skipAuth
      ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
      : request;

  return next(withToken(req, auth.accessToken)).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse)) {
        return throwError(() => error);
      }
      if (error.status === 401 && !skipAuth && auth.refreshToken) {
        return auth.refreshAccessToken().pipe(
          switchMap((tokens) => next(withToken(req, tokens.accessToken))),
          catchError((refreshError: unknown) => {
            auth.clearSession();
            return throwError(() => normalize(refreshError));
          }),
        );
      }
      return throwError(() => normalize(error));
    }),
  );
};

function normalize(error: unknown): unknown {
  if (error instanceof ApiProblemError) {
    return error;
  }
  if (error instanceof HttpErrorResponse) {
    // status 0 — мережа недоступна (офлайн).
    if (error.status === 0) {
      return new ApiProblemError(0, {
        code: 'NETWORK_UNAVAILABLE',
        detail: 'Немає звʼязку із сервером',
      });
    }
    return new ApiProblemError(error.status, toProblem(error.status, error.error));
  }
  return error;
}
