/**
 * Робота з часом. Дані з бекенду — UTC ISO 8601, показуємо в Europe/Kyiv.
 */

export const KYIV_TZ = 'Europe/Kyiv';

const TIME_FMT = new Intl.DateTimeFormat('uk-UA', {
  timeZone: KYIV_TZ,
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

const DATE_FMT = new Intl.DateTimeFormat('uk-UA', {
  timeZone: KYIV_TZ,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
});

const WEEKDAY_FMT = new Intl.DateTimeFormat('uk-UA', {
  timeZone: KYIV_TZ,
  weekday: 'short',
});

const PARTS_FMT = new Intl.DateTimeFormat('en-CA', {
  timeZone: KYIV_TZ,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
});

/** HH:MM у Києві. */
export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '';
  return TIME_FMT.format(new Date(iso));
}

/** DD.MM.YYYY у Києві. */
export function formatDate(iso: string | Date): string {
  return DATE_FMT.format(typeof iso === 'string' ? new Date(iso) : iso);
}

export function formatWeekday(iso: string | Date): string {
  return WEEKDAY_FMT.format(typeof iso === 'string' ? new Date(iso) : iso);
}

/** Календарна дата (YYYY-MM-DD) у Києві для довільного моменту. */
export function toKyivDateKey(input: string | Date): string {
  const date = typeof input === 'string' ? new Date(input) : input;
  const parts = PARTS_FMT.formatToParts(date);
  const get = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((p) => p.type === type)?.value ?? '00';
  return `${get('year')}-${get('month')}-${get('day')}`;
}

/** Локальний час у Києві як хвилини від початку доби. */
export function kyivMinutesOfDay(input: string | Date): number {
  const date = typeof input === 'string' ? new Date(input) : input;
  const parts = PARTS_FMT.formatToParts(date);
  const get = (type: Intl.DateTimeFormatPartTypes) =>
    Number(parts.find((p) => p.type === type)?.value ?? 0);
  return get('hour') * 60 + get('minute');
}

/** Зсув київського часу відносно UTC (мс) на конкретний момент. */
export function kyivOffsetMs(at: Date): number {
  const parts = PARTS_FMT.formatToParts(at);
  const get = (type: Intl.DateTimeFormatPartTypes) =>
    Number(parts.find((p) => p.type === type)?.value ?? 0);
  const asUtc = Date.UTC(
    get('year'),
    get('month') - 1,
    get('day'),
    get('hour'),
    get('minute'),
    get('second'),
  );
  return asUtc - at.getTime();
}

/**
 * Перетворює київські дату+час (YYYY-MM-DD, хвилини від початку доби) у UTC ISO.
 */
export function kyivToUtcIso(dateKey: string, minutesOfDay: number): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const naiveUtc = Date.UTC(y, m - 1, d, 0, minutesOfDay, 0, 0);
  // Перше наближення зсуву, потім уточнення (достатньо для DST-меж).
  let guess = new Date(naiveUtc - kyivOffsetMs(new Date(naiveUtc)));
  guess = new Date(naiveUtc - kyivOffsetMs(guess));
  return guess.toISOString();
}

/** Додає дні до ключа дати YYYY-MM-DD. */
export function addDaysToDateKey(dateKey: string, days: number): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

/** Різниця в календарних днях між двома ключами дат. */
export function diffDateKeys(from: string, to: string): number {
  const parse = (key: string) => {
    const [y, m, d] = key.split('-').map(Number);
    return Date.UTC(y, m - 1, d);
  };
  return Math.round((parse(to) - parse(from)) / 86_400_000);
}

/** Понеділок тижня, до якого належить дата (ISO-тиждень). */
export function startOfKyivWeek(dateKey: string): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  const dow = date.getUTCDay(); // 0 — неділя
  const shift = dow === 0 ? -6 : 1 - dow;
  return addDaysToDateKey(dateKey, shift);
}

/** 1 — понеділок … 7 — неділя. */
export function isoDayOfWeek(dateKey: string): number {
  const [y, m, d] = dateKey.split('-').map(Number);
  const dow = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return dow === 0 ? 7 : dow;
}

/** Різниця у хвилинах (b − a), null якщо бракує даних. */
export function minutesBetween(
  a: string | null | undefined,
  b: string | null | undefined,
): number | null {
  if (!a || !b) return null;
  return Math.round((new Date(b).getTime() - new Date(a).getTime()) / 60_000);
}

/** "HH:MM" → хвилини від початку доби. */
export function parseHhMm(value: string): number {
  const [h, m] = value.split(':').map(Number);
  return h * 60 + m;
}

/** Хвилини від початку доби → "HH:MM". */
export function toHhMm(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}
