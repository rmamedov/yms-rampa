import { Booking, BookingStatus } from '../models/booking.model';

/** Кольорове кодування статусів (STW-08) — єдине у всіх режимах. */
export const STATUS_TONES: Readonly<Record<BookingStatus, string>> = {
  booked: 'slate',
  arrived: 'amber',
  unloading: 'orange',
  completed: 'green',
  no_show: 'red',
  rejected: 'maroon',
  cancelled: 'muted',
};

export const ALL_STATUSES: readonly BookingStatus[] = [
  'booked',
  'arrived',
  'unloading',
  'completed',
  'no_show',
  'rejected',
  'cancelled',
];

export function statusLabelKey(status: BookingStatus): string {
  return `status.${status}`;
}

export function statusTone(status: BookingStatus): string {
  return STATUS_TONES[status];
}

/** Тривалість розвантаження, хв (STW-14). */
export function unloadingDurationMinutes(booking: Booking): number | null {
  if (!booking.unloadingStartedAt || !booking.completedAt) return null;
  return Math.round(
    (new Date(booking.completedAt).getTime() -
      new Date(booking.unloadingStartedAt).getTime()) /
      60_000,
  );
}

/** Час очікування, хв (STW-14). */
export function waitingMinutes(booking: Booking): number | null {
  if (!booking.arrivedAt || !booking.unloadingStartedAt) return null;
  return Math.round(
    (new Date(booking.unloadingStartedAt).getTime() -
      new Date(booking.arrivedAt).getTime()) /
      60_000,
  );
}
