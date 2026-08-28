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

/**
 * EDIT-02: постачальник змінює й скасовує бронювання не пізніше ніж за
 * editDeadlineHours до слоту.
 *
 * Це перевіряє бекенд, але кнопки мають знати те саме: інакше кабінет пропонує
 * дію, яка гарантовано впаде з «Редагування вже недоступне», і користувач не
 * розуміє, що сталося.
 *
 * `editableUntil === null` означає «філія не відповіла» — тоді не блокуємо:
 * краще дати спробувати й показати відповідь бекенду, ніж мовчки заборонити.
 */
export function withinEditDeadline(
  editableUntil: string | null | undefined,
  now: Date = new Date(),
): boolean {
  if (!editableUntil) {
    return true;
  }
  const deadline = new Date(editableUntil);
  return Number.isNaN(deadline.getTime()) || now <= deadline;
}
