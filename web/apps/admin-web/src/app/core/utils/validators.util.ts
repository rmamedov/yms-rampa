/** Прості валідатори полів адмінки. Повертають ключ помилки або null. */

const PHONE_RE = /^\+380\d{9}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const EDRPOU_RE = /^(\d{8}|\d{10})$/;

/** Телефон у форматі +380XXXXXXXXX (STC-02). Порожнє значення дозволене. */
export function validatePhone(value: string | null): string | null {
  if (value === null || value.trim() === '') {
    return null;
  }
  return PHONE_RE.test(value.trim()) ? null : 'store.error.phone';
}

export function validateRequiredPhone(value: string): string | null {
  if (!value || value.trim() === '') {
    return 'suppliers.error.phone';
  }
  return PHONE_RE.test(value.trim()) ? null : 'suppliers.error.phone';
}

export function validateEmail(value: string, errorKey = 'staff.error.email'): string | null {
  return EMAIL_RE.test((value ?? '').trim()) ? null : errorKey;
}

/** Код ЄДРПОУ — 8 або 10 цифр (SUP-01). */
export function validateEdrpou(value: string): string | null {
  return EDRPOU_RE.test((value ?? '').trim()) ? null : 'suppliers.error.edrpou';
}

/** Назва для відображення — 1..120 символів (STC-02). */
export function validateDisplayName(value: string): string | null {
  const trimmed = (value ?? '').trim();
  return trimmed.length >= 1 && trimmed.length <= 120
    ? null
    : 'store.error.displayName';
}

/** addressOverride — nullable, до 200 символів (STC-07). */
export function validateAddressOverride(value: string | null): string | null {
  if (value === null || value.trim() === '') {
    return null;
  }
  return value.trim().length <= 200 ? null : 'store.error.addressOverride';
}

/** Причина — обовʼязкова, до 200 символів (STC-12, STC-50). */
export function validateReason(value: string, errorKey: string): string | null {
  const trimmed = (value ?? '').trim();
  return trimmed.length >= 1 && trimmed.length <= 200 ? null : errorKey;
}

/** maxVehicleWeightTons: 1.0–40.0 з кроком 0.5 (STC-30). */
export function validateMaxWeight(value: number | null): string | null {
  if (value === null || Number.isNaN(value)) {
    return 'limits.error.maxWeight';
  }
  if (value < 1 || value > 40) {
    return 'limits.error.maxWeight';
  }
  // множення на 2 прибирає похибку float для кроку 0.5
  const doubled = Math.round(value * 2);
  if (Math.abs(doubled / 2 - value) > 1e-9) {
    return 'limits.error.maxWeight';
  }
  return null;
}

/** Lead time у годинах: ціле 0..168. */
export function validateLeadTime(value: number): string | null {
  return Number.isInteger(value) && value >= 0 && value <= 168
    ? null
    : 'limits.error.leadTime';
}

/** Горизонт бронювання: ціле 1..90 днів. */
export function validateHorizon(value: number): string | null {
  return Number.isInteger(value) && value >= 1 && value <= 90
    ? null
    : 'limits.error.horizon';
}

export function validateRampName(value: string | null): string | null {
  if (value === null || value.trim() === '') {
    return null;
  }
  return value.trim().length <= 60 ? null : 'slots.error.rampName';
}
