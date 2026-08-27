import {
  Booking,
  BookingStatus,
  PartialUnloadReason,
  RejectReason,
} from '../models/booking.model';
import { StaffRole } from '../models/auth.model';
import { TranslateParams } from '../i18n/i18n.service';
import { toKyivDateKey } from './date.util';

/** Дії магазину над бронюванням (розділ 9.4, 9.5, 9.13). */
export type BookingActionId =
  | 'startUnloading'
  | 'complete'
  | 'noShow'
  | 'reject'
  | 'delay'
  | 'clearDelay'
  | 'reassign'
  | 'log';

export interface ActionAvailability {
  readonly id: BookingActionId;
  readonly enabled: boolean;
  /** Ключ i18n для тултіпа-причини, якщо дія недоступна. */
  readonly reasonKey: string | null;
  readonly reasonParams: TranslateParams | null;
}

export interface ActionContext {
  /** Поточний час (ISO UTC). */
  readonly now: string;
  /** Дата, яку зараз переглядають (YYYY-MM-DD, Europe/Kyiv). */
  readonly viewDateKey: string;
  /** Сьогоднішня дата у Києві. */
  readonly todayKey: string;
  readonly role: StaffRole;
  /** Чи існує інша рампа з вільним слотом у той самий час (STW-41/42). */
  readonly hasFreeRampForReassign: boolean;
}

export const TERMINAL_STATUSES: readonly BookingStatus[] = [
  'completed',
  'cancelled',
  'no_show',
  'rejected',
];

export function isTerminal(status: BookingStatus): boolean {
  return TERMINAL_STATUSES.includes(status);
}

/** Дозволені статусні переходи магазину (діаграма розділу 9.4). */
export const STORE_TRANSITIONS: Readonly<
  Record<BookingStatus, readonly BookingStatus[]>
> = {
  booked: ['no_show'],
  arrived: ['unloading', 'rejected'],
  unloading: ['completed'],
  completed: [],
  cancelled: [],
  no_show: [],
  rejected: [],
};

export function canStoreTransition(
  from: BookingStatus,
  to: BookingStatus,
): boolean {
  return STORE_TRANSITIONS[from].includes(to);
}

/**
 * Минула дата read-only для статусних дій, крім дозакриття вчорашніх unloading
 * (STW-22).
 */
export function isReadOnlyDate(ctx: ActionContext): boolean {
  return ctx.viewDateKey < ctx.todayKey;
}

function deny(
  id: BookingActionId,
  reasonKey: string,
  reasonParams: TranslateParams | null = null,
): ActionAvailability {
  return { id, enabled: false, reasonKey, reasonParams };
}

function allow(id: BookingActionId): ActionAvailability {
  return { id, enabled: true, reasonKey: null, reasonParams: null };
}

export function evaluateAction(
  booking: Booking,
  action: BookingActionId,
  ctx: ActionContext,
): ActionAvailability {
  if (action === 'log') {
    return allow('log');
  }

  const readOnly = isReadOnlyDate(ctx);
  const nowMs = new Date(ctx.now).getTime();

  switch (action) {
    case 'startUnloading': {
      if (booking.status !== 'arrived') {
        return deny('startUnloading', 'action.disabled.wrongStatus', {
          status: 'status.arrived',
        });
      }
      if (readOnly) {
        return deny('startUnloading', 'action.disabled.pastDate');
      }
      return allow('startUnloading');
    }

    case 'complete': {
      if (booking.status !== 'unloading') {
        return deny('complete', 'action.disabled.wrongStatus', {
          status: 'status.unloading',
        });
      }
      // Дозакриття зміни: вчорашні unloading закривати можна.
      return allow('complete');
    }

    case 'noShow': {
      if (booking.status !== 'booked') {
        return deny('noShow', 'action.disabled.wrongStatus', {
          status: 'status.booked',
        });
      }
      if (readOnly) {
        return deny('noShow', 'action.disabled.pastDate');
      }
      if (nowMs <= new Date(booking.slotEnd).getTime()) {
        return deny('noShow', 'action.disabled.noShowTooEarly');
      }
      return allow('noShow');
    }

    case 'reject': {
      if (booking.status !== 'arrived') {
        return deny('reject', 'action.disabled.wrongStatus', {
          status: 'status.arrived',
        });
      }
      if (readOnly) {
        return deny('reject', 'action.disabled.pastDate');
      }
      return allow('reject');
    }

    case 'delay': {
      if (booking.status !== 'booked' && booking.status !== 'arrived') {
        return deny('delay', 'action.disabled.terminal');
      }
      if (readOnly) {
        return deny('delay', 'action.disabled.pastDate');
      }
      return allow('delay');
    }

    case 'clearDelay': {
      if (!booking.delayed.flag) {
        return deny('clearDelay', 'action.disabled.terminal');
      }
      if (readOnly) {
        return deny('clearDelay', 'action.disabled.pastDate');
      }
      return allow('clearDelay');
    }

    case 'reassign': {
      if (booking.status !== 'booked' && booking.status !== 'arrived') {
        return deny('reassign', 'action.disabled.terminal');
      }
      if (readOnly) {
        return deny('reassign', 'action.disabled.pastDate');
      }
      if (!ctx.hasFreeRampForReassign) {
        return deny('reassign', 'action.disabled.noFreeRamp');
      }
      return allow('reassign');
    }
  }
}

export const ALL_ACTIONS: readonly BookingActionId[] = [
  'startUnloading',
  'complete',
  'noShow',
  'reject',
  'delay',
  'clearDelay',
  'reassign',
  'log',
];

export function evaluateActions(
  booking: Booking,
  ctx: ActionContext,
): Record<BookingActionId, ActionAvailability> {
  const result = {} as Record<BookingActionId, ActionAvailability>;
  for (const action of ALL_ACTIONS) {
    result[action] = evaluateAction(booking, action, ctx);
  }
  return result;
}

/**
 * Наступний допустимий статусний перехід для свайпу управо (STW-31).
 * Повертає null, якщо переходу немає.
 */
export function nextSwipeAction(
  booking: Booking,
  ctx: ActionContext,
): BookingActionId | null {
  const order: BookingActionId[] = [
    'startUnloading',
    'complete',
    'noShow',
  ];
  for (const action of order) {
    if (evaluateAction(booking, action, ctx).enabled) {
      return action;
    }
  }
  return null;
}

/** Свайп-дії, які вимагають підтвердження (STW-31). */
export function swipeNeedsConfirm(action: BookingActionId): boolean {
  return action === 'noShow' || action === 'complete';
}

// ---------------------------------------------------------------------------
// Валідація форм
// ---------------------------------------------------------------------------

export interface ValidationResult {
  readonly valid: boolean;
  /** Ключі i18n помилок, у порядку виявлення. */
  readonly errors: readonly string[];
}

const ok: ValidationResult = { valid: true, errors: [] };

function fail(...errors: string[]): ValidationResult {
  return { valid: false, errors };
}

export interface CompleteFormValue {
  readonly unloadedPalletsCount: number;
  readonly partialUnload: boolean;
  readonly reason: PartialUnloadReason | null;
  readonly comment: string;
}

/**
 * STW-36: якщо розвантажено менше заявленого, partialUnload вмикається
 * автоматично і потребує причину; для «інше» коментар обовʼязковий.
 */
export function normalizeCompleteForm(
  value: CompleteFormValue,
  plannedPallets: number,
): CompleteFormValue {
  const partial =
    value.partialUnload || value.unloadedPalletsCount < plannedPallets;
  return { ...value, partialUnload: partial };
}

export function validateCompleteForm(
  value: CompleteFormValue,
  plannedPallets: number,
): ValidationResult {
  const normalized = normalizeCompleteForm(value, plannedPallets);
  const errors: string[] = [];

  if (
    !Number.isInteger(normalized.unloadedPalletsCount) ||
    normalized.unloadedPalletsCount < 0 ||
    normalized.unloadedPalletsCount > plannedPallets
  ) {
    errors.push('complete.invalidCount');
  }
  if (normalized.partialUnload && !normalized.reason) {
    errors.push('complete.partialReasonRequired');
  }
  if (
    normalized.partialUnload &&
    normalized.reason === 'other' &&
    normalized.comment.trim().length === 0
  ) {
    errors.push('complete.commentRequired');
  }
  return errors.length ? { valid: false, errors } : ok;
}

export interface RejectFormValue {
  readonly reason: RejectReason | null;
  readonly comment: string;
}

export function validateRejectForm(value: RejectFormValue): ValidationResult {
  if (!value.reason) {
    return fail('reject.reasonRequired');
  }
  if (value.reason === 'other' && value.comment.trim().length === 0) {
    return fail('reject.commentRequired');
  }
  return ok;
}

export interface DelayFormValue {
  readonly reason: string | null;
  readonly comment: string;
  /** ISO UTC або null */
  readonly eta: string | null;
}

/**
 * STW-18: причина обовʼязкова, коментар ≤ 500, ETA обовʼязковий, пізніший за
 * slotStart і в межах поточної київської доби.
 */
export function validateDelayForm(
  value: DelayFormValue,
  booking: Pick<Booking, 'slotStart'>,
): ValidationResult {
  const errors: string[] = [];
  if (!value.reason) {
    errors.push('delay.reasonRequired');
  }
  if (value.comment.length > 500) {
    errors.push('delay.commentTooLong');
  }
  if (!value.eta) {
    errors.push('delay.etaRequired');
  } else {
    if (new Date(value.eta).getTime() <= new Date(booking.slotStart).getTime()) {
      errors.push('delay.etaBeforeSlot');
    }
    if (toKyivDateKey(value.eta) !== toKyivDateKey(booking.slotStart)) {
      errors.push('delay.etaOutOfDay');
    }
  }
  return errors.length ? { valid: false, errors } : ok;
}

export interface WalkInFormValue {
  readonly supplierId: string | null;
  readonly externalSupplierName: string;
  readonly useExternalSupplier: boolean;
  readonly plateNumber: string;
  readonly weightTons: number | null;
  readonly palletsCount: number | null;
  readonly orderId: string;
  readonly rampId: string | null;
  readonly slotStart: string | null;
}

/** STW-37/38. */
export function validateWalkInForm(
  value: WalkInFormValue,
  maxVehicleWeightTons: number,
): ValidationResult {
  const errors: string[] = [];

  const supplierOk = value.useExternalSupplier
    ? value.externalSupplierName.trim().length > 0
    : !!value.supplierId;
  if (!supplierOk) {
    errors.push('walkIn.supplierRequired');
  }
  if (value.plateNumber.trim().length === 0) {
    errors.push('walkIn.plateRequired');
  }
  if (value.weightTons === null || value.weightTons <= 0) {
    errors.push('walkIn.weightRequired');
  } else if (value.weightTons > maxVehicleWeightTons) {
    errors.push('error.VEHICLE_TOO_HEAVY');
  }
  if (
    value.palletsCount === null ||
    !Number.isInteger(value.palletsCount) ||
    value.palletsCount < 1 ||
    value.palletsCount > 33
  ) {
    errors.push('walkIn.palletsRange');
  }
  if (!value.rampId || !value.slotStart) {
    errors.push('walkIn.slotRequired');
  }
  return errors.length ? { valid: false, errors } : ok;
}
