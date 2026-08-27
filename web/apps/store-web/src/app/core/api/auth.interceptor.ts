import {
  HttpErrorResponse,
  HttpEvent,
  HttpHandlerFn,
  HttpInterceptorFn,
  HttpRequest,
} from '@angular/common/http';
import { inject } from '@angular/core';
import {
  BehaviorSubject,
  Observable,
  catchError,
  filter,
  switchMap,
  take,
  throwError,
} from 'rxjs';
import { AuthService } from '../auth/auth.service';
import { TokenStorageService } from '../auth/token-storage.service';

/** Спільний стан refresh для всіх паралельних запитів. */
let refreshInFlight = false;
const refreshedToken$ = new BehaviorSubject<string | null>(null);

function withAuth(
  request: HttpRequest<unknown>,
  token: string | null,
): HttpRequest<unknown> {
  if (!token) return request;
  return request.clone({
    setHeaders: { Authorization: `Bearer ${token}` },
  });
}

/**
 * Додає Authorization: Bearer <access token> і виконує refresh при 401,
 * повторюючи оригінальний запит рівно один раз.
 */
export const authInterceptor: HttpInterceptorFn = (
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> => {
  const storage = inject(TokenStorageService);
  const auth = inject(AuthService);

  const isAuthEndpoint =
    request.url.includes('/auth/login') || request.url.includes('/auth/refresh');

  const accessToken = storage.getTokens()?.accessToken ?? null;
  const authorized = isAuthEndpoint ? request : withAuth(request, accessToken);

  return next(authorized).pipe(
    catchError((error: unknown) => {
      if (
        !(error instanceof HttpErrorResponse) ||
        error.status !== 401 ||
        isAuthEndpoint
      ) {
        return throwError(() => error);
      }

      if (refreshInFlight) {
        return refreshedToken$.pipe(
          filter((token): token is string => token !== null),
          take(1),
          switchMap((token) => next(withAuth(request, token))),
        );
      }

      refreshInFlight = true;
      refreshedToken$.next(null);

      return auth.refresh().pipe(
        switchMap((tokens) => {
          refreshInFlight = false;
          refreshedToken$.next(tokens.accessToken);
          return next(withAuth(request, tokens.accessToken));
        }),
        catchError((refreshError: unknown) => {
          refreshInFlight = false;
          auth.logout();
          return throwError(() => refreshError);
        }),
      );
    }),
  );
};
