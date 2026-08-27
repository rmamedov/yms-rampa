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

  private readonly byCode: Record<string, string> = {
    AUTH_INVALID_CREDENTIALS: 'login.error.invalidCredentials',
    AUTH_ACCOUNT_DISABLED: 'login.error.accountDisabled',
    AUTH_ACCOUNT_LOCKED: 'login.error.accountLocked',
    AUTH_RATE_LIMITED: 'login.error.rateLimited',
    AUTH_ROLE_NOT_ALLOWED: 'login.error.notDriver',
    AUTH_TOKEN_INVALID: 'session.expired',
    AUTH_REFRESH_REUSED: 'session.expired',
    NETWORK_UNAVAILABLE: 'login.error.network',
    BOOKING_ALREADY_ARRIVED: 'arrive.alreadyArrived',
    BOOKING_CANCELLED: 'arrive.cancelled',
    ARRIVAL_WINDOW_NOT_OPEN: 'arrive.windowClosed',
    DELAY_ETA_IN_PAST: 'delay.error.etaPast',
    SLOT_ALREADY_BOOKED: 'error.slotAlreadyBooked',
    SLOT_HELD: 'error.slotHeld',
    VEHICLE_TOO_HEAVY: 'error.vehicleTooHeavy',
    DATE_OUT_OF_HORIZON: 'error.dateOutOfHorizon',
    BOOKING_LIMIT_EXCEEDED: 'error.bookingLimitExceeded',
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
