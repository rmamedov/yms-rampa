/**
 * Нормалізація українського номера телефону до E.164 (+380XXXXXXXXX), AUTH-23 / DRV-06.
 * Приймає формати: 0XX XXX XX XX, 380XXXXXXXXX, +380XXXXXXXXX,
 * з пробілами, дужками, дефісами та нерозривними пробілами.
 */

const UA_NATIONAL_LENGTH = 9; // після коду країни 380

/** Повертає E.164-рядок або null, якщо номер не є коректним українським. */
export function normalizePhone(input: string | null | undefined): string | null {
  if (!input) {
    return null;
  }
  // Лишаємо лише цифри, зберігаючи ознаку початкового «+».
  const digits = input.replace(/\D+/g, '');
  if (digits.length === 0) {
    return null;
  }

  let national: string;
  if (digits.startsWith('380')) {
    national = digits.slice(3);
  } else if (digits.startsWith('0')) {
    national = digits.slice(1);
  } else if (digits.length === UA_NATIONAL_LENGTH) {
    // Ввели без 0 і без коду країни: 671234567
    national = digits;
  } else if (digits.startsWith('80')) {
    // Старий формат 8-0XX (набір через вісімку).
    national = digits.slice(2);
  } else {
    return null;
  }

  if (national.length !== UA_NATIONAL_LENGTH) {
    return null;
  }
  // Перша цифра національного номера в Україні ніколи не 0.
  if (national.startsWith('0')) {
    return null;
  }
  return `+380${national}`;
}

export function isValidPhone(input: string | null | undefined): boolean {
  return normalizePhone(input) !== null;
}

/** Читабельне подання E.164 для UI: +380 67 123 45 67. */
export function formatPhone(e164: string | null | undefined): string {
  const normalized = normalizePhone(e164);
  if (!normalized) {
    return e164 ?? '';
  }
  const d = normalized.slice(4); // 9 цифр
  return `+380 ${d.slice(0, 2)} ${d.slice(2, 5)} ${d.slice(5, 7)} ${d.slice(7, 9)}`;
}
