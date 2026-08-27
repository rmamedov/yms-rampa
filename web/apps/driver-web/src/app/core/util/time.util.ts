/**
 * Робота з часом: у сховищі й API — UTC ISO 8601, у інтерфейсі — Europe/Kyiv (DRV-05).
 */

export const KYIV_TZ = 'Europe/Kyiv';

/** Вікно доступності кнопки «На місці» — 60 хв до початку слоту (DRV-24). */
export const ARRIVE_WINDOW_BEFORE_MS = 60 * 60 * 1000;

const timeFormatter = new Intl.DateTimeFormat('uk-UA', {
  timeZone: KYIV_TZ,
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

const dateFormatter = new Intl.DateTimeFormat('uk-UA', {
  timeZone: KYIV_TZ,
  day: '2-digit',
  month: '2-digit',
});

const dateTimeFormatter = new Intl.DateTimeFormat('uk-UA', {
  timeZone: KYIV_TZ,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

/** HH:MM у Europe/Kyiv. */
export function formatKyivTime(iso: string | number | Date): string {
  return timeFormatter.format(new Date(iso));
}

/** DD.MM у Europe/Kyiv. */
export function formatKyivDayMonth(iso: string | number | Date): string {
  return dateFormatter.format(new Date(iso));
}

/** DD.MM.YYYY HH:MM у Europe/Kyiv. */
export function formatKyivDateTime(iso: string | number | Date): string {
  return dateTimeFormatter.format(new Date(iso)).replace(', ', ' ');
}

/** «HH:MM–HH:MM» для картки точки (DRV-15). */
export function formatSlotRange(startIso: string, endIso: string): string {
  return `${formatKyivTime(startIso)}–${formatKyivTime(endIso)}`;
}

/** Поточна календарна дата в Europe/Kyiv у форматі YYYY-MM-DD. */
export function kyivDateKey(at: Date | number = Date.now()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: KYIV_TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date(at));
  // en-CA дає YYYY-MM-DD
  return parts;
}

/** Зсув дати на n днів у межах календаря Києва. */
export function addDaysToDateKey(dateKey: string, days: number): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const base = Date.UTC(y, m - 1, d) + days * 86_400_000;
  const dt = new Date(base);
  const yy = dt.getUTCFullYear();
  const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(dt.getUTCDate()).padStart(2, '0');
  return `${yy}-${mm}-${dd}`;
}

export type DateChipKind = 'today' | 'tomorrow' | 'other';

export function dateChipKind(
  dateKey: string,
  now: Date | number = Date.now(),
): DateChipKind {
  const today = kyivDateKey(now);
  if (dateKey === today) {
    return 'today';
  }
  if (dateKey === addDaysToDateKey(today, 1)) {
    return 'tomorrow';
  }
  return 'other';
}

/** Мітка чипа дати: «Сьогодні» / «Завтра» / «29.08» (DRV-13). */
export function dateChipLabel(
  dateKey: string,
  now: Date | number,
  t: (key: string) => string,
): string {
  const kind = dateChipKind(dateKey, now);
  if (kind === 'today') {
    return t('sheet.today');
  }
  if (kind === 'tomorrow') {
    return t('sheet.tomorrow');
  }
  const [, m, d] = dateKey.split('-');
  return `${d}.${m}`;
}

export type ArriveWindowState = 'too_early' | 'open' | 'late';

/**
 * Стан вікна відмітки «На місці» (DRV-24):
 *  - too_early — раніше ніж за 60 хв до початку слоту, кнопка неактивна;
 *  - open — від T−60 хв до кінця слоту;
 *  - late — після кінця слоту: кнопка доступна, але система поставить `delayed`.
 */
export function arriveWindowState(
  slotStartIso: string,
  slotEndIso: string,
  now: number = Date.now(),
): ArriveWindowState {
  const start = new Date(slotStartIso).getTime();
  const end = new Date(slotEndIso).getTime();
  if (now < start - ARRIVE_WINDOW_BEFORE_MS) {
    return 'too_early';
  }
  if (now > end) {
    return 'late';
  }
  return 'open';
}

/** Значення для <input type="datetime-local"> у київському часі. */
export function toKyivLocalInputValue(at: Date | number = Date.now()): string {
  const parts = new Intl.DateTimeFormat('sv-SE', {
    timeZone: KYIV_TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(at));
  // sv-SE дає "YYYY-MM-DD HH:MM"
  return parts.replace(' ', 'T');
}

/** Зворотне перетворення: київський локальний рядок → UTC ISO 8601. */
export function kyivLocalInputToIso(value: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
    return null;
  }
  // Підбираємо UTC-момент, київське подання якого дорівнює введеному значенню.
  const naive = Date.parse(`${value}:00Z`);
  if (Number.isNaN(naive)) {
    return null;
  }
  let guess = naive;
  for (let i = 0; i < 3; i++) {
    const rendered = toKyivLocalInputValue(guess);
    const diff = Date.parse(`${value}:00Z`) - Date.parse(`${rendered}:00Z`);
    if (diff === 0) {
      break;
    }
    guess += diff;
  }
  return new Date(guess).toISOString();
}
