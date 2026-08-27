/**
 * Чисті правила валідації форм постачальника.
 * Повертають ключ повідомлення (i18n) або null, якщо значення коректне.
 */

export const PLATE_MIN_LENGTH = 4;
export const PLATE_MAX_LENGTH = 12;
export const PALLETS_MIN = 1;
export const PALLETS_MAX = 33;
export const ORDER_ID_MAX_LENGTH = 64;

/** Нормалізація держномера: верхній регістр, без пробілів і дефісів. */
export function normalizePlate(raw: string): string {
  return (raw ?? '')
    .replace(/[\s\-_]/g, '')
    .toLocaleUpperCase('uk-UA')
    .trim();
}

const PLATE_ALLOWED = /^[0-9A-ZА-ЯІЇЄҐ]+$/u;
/** Стандарт: 2 літери, 4 цифри, 2 літери (кирилиця або латиниця). */
const PLATE_STANDARD = /^[A-ZА-ЯІЇЄҐ]{2}\d{4}[A-ZА-ЯІЇЄҐ]{2}$/u;

export function validatePlate(raw: string): string | null {
  const plate = normalizePlate(raw);
  if (!plate) {
    return 'validation.plateRequired';
  }
  if (plate.length < PLATE_MIN_LENGTH || plate.length > PLATE_MAX_LENGTH) {
    return 'validation.plateLength';
  }
  if (!PLATE_ALLOWED.test(plate)) {
    return 'validation.plateChars';
  }
  return null;
}

/** Номер валідний, але не у стандартному українському форматі — мʼяка підказка. */
export function isStandardPlate(raw: string): boolean {
  return PLATE_STANDARD.test(normalizePlate(raw));
}

export function validateWeightTons(
  value: number | null | undefined,
): string | null {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return 'validation.weightRequired';
  }
  if (value <= 0) {
    return 'validation.weightPositive';
  }
  return null;
}

/** SUP-BOOK-04 / BOOK-01: тоннаж авто проти maxVehicleWeightTons філії. */
export function validateVehicleAgainstStore(
  weightTons: number,
  maxVehicleWeightTons: number,
): string | null {
  const base = validateWeightTons(weightTons);
  if (base) {
    return base;
  }
  if (weightTons > maxVehicleWeightTons) {
    return 'validation.weightTooHeavy';
  }
  return null;
}

export function validatePallets(
  value: number | null | undefined,
): string | null {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return 'validation.palletsRequired';
  }
  if (!Number.isInteger(value) || value < PALLETS_MIN || value > PALLETS_MAX) {
    return 'validation.palletsRange';
  }
  return null;
}

export function validateOrderId(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }
  return value.length > ORDER_ID_MAX_LENGTH ? 'validation.orderIdLength' : null;
}

const PHONE_RE = /^\+380\d{9}$/;

/** Нормалізація телефону водія до +380XXXXXXXXX. */
export function normalizePhone(raw: string): string {
  const digits = (raw ?? '').replace(/[^\d+]/g, '');
  if (digits.startsWith('+')) {
    return digits;
  }
  if (digits.startsWith('380')) {
    return `+${digits}`;
  }
  if (digits.startsWith('0')) {
    return `+38${digits}`;
  }
  return digits ? `+${digits}` : '';
}

export function validatePhone(raw: string): string | null {
  const phone = normalizePhone(raw);
  if (!phone) {
    return 'validation.phoneRequired';
  }
  return PHONE_RE.test(phone) ? null : 'validation.phoneFormat';
}

export function validateEmail(raw: string): string | null {
  const value = (raw ?? '').trim();
  if (!value) {
    return 'validation.emailRequired';
  }
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
    ? null
    : 'validation.emailRequired';
}
