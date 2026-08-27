/**
 * Робота з часом: у сховищі й API — UTC ISO 8601, у інтерфейсі — Europe/Kyiv (DRV-05).
 */

export const KYIV_TZ = 'Europe/Kyiv';

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
