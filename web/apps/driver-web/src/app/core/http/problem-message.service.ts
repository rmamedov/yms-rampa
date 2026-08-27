import { Injectable, inject } from '@angular/core';
import { ApiProblemError } from '../models/problem.model';
import { I18nService } from '../i18n/i18n.service';

/**
 * Перетворює помилку RFC 7807 на текст для водія.
 * Пріоритет: відомий код → detail із бекенду → загальне повідомлення.
 */
@Injectable({ providedIn: 'root' })
export class ProblemMessageService {
  private readonly i18n = inject(I18nService);

  /**
   * Лише коди, які реально можуть прийти у контурі водія:
   * identity-partner-service (AuthException::errorCode) і booking-service
   * (DomainProblem::errorCode) на маршруті route-sheet та трьох маршрутах
   * дій водія. Коди контуру постачальника (SLOT_*, VEHICLE_TOO_HEAVY тощо)
   * сюди не долітають.
   *
   * VALIDATION_FAILED свідомо НЕ мапиться: бекенд віддає в `detail`
   * готове українське пояснення («ETA має бути в майбутньому», «Номер
   * замовлення можна вказати лише до початку розвантаження»), і воно
   * точніше за будь-який загальний текст.
   */
  private readonly byCode: Record<string, string> = {
    AUTH_INVALID_CREDENTIALS: 'login.error.invalidCredentials',
    AUTH_ACCOUNT_DISABLED: 'login.error.accountDisabled',
    AUTH_ACCOUNT_LOCKED: 'login.error.accountLocked',
    AUTH_ROLE_NOT_ALLOWED: 'login.error.notDriver',
    PARTNER_LOGIN_INVALID: 'login.error.phoneInvalid',
    AUTH_TOKEN_INVALID: 'session.expired',
    AUTH_TOKEN_EXPIRED: 'session.expired',
    AUTH_REFRESH_REUSED: 'session.expired',
    NETWORK_UNAVAILABLE: 'login.error.network',
    ACCESS_DENIED: 'error.accessDenied',
    NOT_FOUND: 'error.notFound',
    ROUTE_NOT_FOUND: 'error.notFound',
    BOOKING_NOT_FOUND: 'error.notFound',
    INVALID_STATUS_TRANSITION: 'error.invalidTransition',
    UPSTREAM_UNAVAILABLE: 'error.upstreamUnavailable',
    INTERNAL_ERROR: 'error.generic',
  };

  /** Текст помилки для показу водієві. */
  messageFor(error: unknown, fallbackKey = 'login.error.generic'): string {
    if (error instanceof ApiProblemError) {
      const key = this.byCode[error.code];
      if (key && this.i18n.has(key)) {
        return this.i18n.t(key);
      }
      if (error.problem.detail) {
        return error.problem.detail;
      }
      const violation = error.problem.violations?.[0];
      if (violation) {
        return violation.message;
      }
    }
    return this.i18n.t(fallbackKey);
  }

  /** Нормалізований код помилки або порожній рядок. */
  codeOf(error: unknown): string {
    return error instanceof ApiProblemError ? error.code : '';
  }

  isNetworkError(error: unknown): boolean {
    return (
      error instanceof ApiProblemError &&
      (error.status === 0 || error.code === 'NETWORK_UNAVAILABLE')
    );
  }
}
