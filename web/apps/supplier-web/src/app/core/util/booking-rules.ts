import type { BookingStatus } from '../models/models';

/**
 * SUP-RS-06 / SUP-RS-07: правила доступності дій постачальника над точкою
 * маршрутного листа залежно від статусу бронювання.
 */
const LOCKED: readonly BookingStatus[] = [
  'arrived',
  'unloading',
  'completed',
  'no_show',
  'rejected',
  'cancelled',
];

export function isLocked(status: BookingStatus): boolean {
  return LOCKED.includes(status);
}

/** Перенесення можливе лише для статусу booked. */
export function canTransfer(status: BookingStatus): boolean {
  return status === 'booked';
}

export function canCancel(status: BookingStatus): boolean {
  return status === 'booked';
}

/** Зміна водія та авто дозволена до статусу arrived. */
export function canChangeDriverOrVehicle(status: BookingStatus): boolean {
  return status === 'booked';
}
