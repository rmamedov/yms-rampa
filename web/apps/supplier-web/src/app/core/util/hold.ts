import type { HoldSession } from '../models/models';

/** Дефолти движка холдів (HOLD-01, HOLD-02). */
export const HOLD_TTL_MINUTES = 5;
export const HOLD_MAX_MINUTES = 15;

export function holdRemainingSeconds(
  hold: Pick<HoldSession, 'expiresAt'>,
  now: Date,
): number {
  const diff = new Date(hold.expiresAt).getTime() - now.getTime();
  return Math.max(0, Math.floor(diff / 1000));
}

export function isHoldExpired(
  hold: Pick<HoldSession, 'expiresAt'>,
  now: Date,
): boolean {
  return holdRemainingSeconds(hold, now) <= 0;
}

/**
 * Продовження холду при активності: TTL знову 5 хв, але не далі за maxUntil
 * (сумарний максимум життя однієї hold — holdMaxMinutes).
 */
export function extendedExpiry(
  now: Date,
  maxUntil: Date,
  ttlMinutes: number = HOLD_TTL_MINUTES,
): Date {
  const candidate = new Date(now.getTime() + ttlMinutes * 60000);
  return candidate.getTime() > maxUntil.getTime() ? maxUntil : candidate;
}

/** Чи має сенс надсилати heartbeat: hold живий і межа ще не досягнута. */
export function canExtendHold(
  hold: Pick<HoldSession, 'expiresAt' | 'maxUntil'>,
  now: Date,
): boolean {
  if (isHoldExpired(hold, now)) {
    return false;
  }
  return new Date(hold.maxUntil).getTime() > new Date(hold.expiresAt).getTime();
}
