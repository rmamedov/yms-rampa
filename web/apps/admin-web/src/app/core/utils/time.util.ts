/**
 * Робота з часом. У UI все показуємо в Europe/Kyiv, у моделях зберігаємо UTC ISO 8601 (ADM-03).
 */

export const KYIV_TZ = 'Europe/Kyiv';
export const UK_LOCALE = 'uk-UA';

const TIME_RE = /^([01]\d|2[0-3]):([0-5]\d)$/;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

export function isValidTime(value: string): boolean {
  return TIME_RE.test(value);
}

export function isValidDate(value: string): boolean {
  if (!DATE_RE.test(value)) {
    return false;
  }
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

/** 'HH:MM' → хвилини від початку доби. -1 для невалідного значення. */
export function timeToMinutes(value: string): number {
  if (!isValidTime(value)) {
    return -1;
  }
  const [h, m] = value.split(':');
  return Number(h) * 60 + Number(m);
}

export function minutesToTime(minutes: number): string {
  const normalized = ((minutes % 1440) + 1440) % 1440;
  const h = Math.floor(normalized / 60);
  const m = normalized % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/** Крок сітки часу — 5 хвилин (STC-11). */
export function isOnFiveMinuteStep(value: string): boolean {
  const minutes = timeToMinutes(value);
  return minutes >= 0 && minutes % 5 === 0;
}

/** Локальна дата (YYYY-MM-DD) у Europe/Kyiv для заданого моменту. */
export function kyivDate(at: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: KYIV_TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(at);
  return parts;
}

export function addDays(date: string, days: number): string {
  const base = new Date(`${date}T00:00:00Z`);
  base.setUTCDate(base.getUTCDate() + days);
  return base.toISOString().slice(0, 10);
}

export function diffDays(from: string, to: string): number {
  const a = new Date(`${from}T00:00:00Z`).getTime();
  const b = new Date(`${to}T00:00:00Z`).getTime();
  return Math.round((b - a) / 86_400_000);
}

/** ISO-8601 день тижня: 1 = понеділок … 7 = неділя. */
export function dayOfWeek(date: string): number {
  const jsDay = new Date(`${date}T00:00:00Z`).getUTCDay();
  return jsDay === 0 ? 7 : jsDay;
}

/** UTC ISO → «дд.мм.рррр, гг:хв» у Europe/Kyiv. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }
  return new Intl.DateTimeFormat(UK_LOCALE, {
    timeZone: KYIV_TZ,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

/** 'YYYY-MM-DD' → 'дд.мм.рррр'. */
export function formatDate(date: string | null | undefined): string {
  if (!date || !isValidDate(date)) {
    return '—';
  }
  const [y, m, d] = date.split('-');
  return `${d}.${m}.${y}`;
}

export function formatDuration(ms: number): string {
  if (ms < 1000) {
    return `${ms} мс`;
  }
  const totalSeconds = Math.round(ms / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return minutes > 0 ? `${minutes} хв ${seconds} с` : `${seconds} с`;
}

/** Зсув Europe/Kyiv у хвилинах для конкретного моменту (враховує DST). */
export function kyivOffsetMinutes(at: Date): number {
  const name =
    new Intl.DateTimeFormat('en-US', {
      timeZone: KYIV_TZ,
      timeZoneName: 'longOffset',
    })
      .formatToParts(at)
      .find((part) => part.type === 'timeZoneName')?.value ?? 'GMT+00:00';
  const match = /GMT([+-])(\d{2}):(\d{2})/.exec(name);
  if (!match) {
    return 0;
  }
  const sign = match[1] === '-' ? -1 : 1;
  return sign * (Number(match[2]) * 60 + Number(match[3]));
}

/**
 * Локальні дата+час магазину → UTC ISO 8601 (ADM-03).
 * Бекенд приймає межі блокувань саме в UTC.
 */
export function kyivDateTimeToIso(date: string, time: string): string {
  const asUtc = new Date(`${date}T${time}:00Z`);
  if (Number.isNaN(asUtc.getTime())) {
    return '';
  }
  return new Date(asUtc.getTime() - kyivOffsetMinutes(asUtc) * 60_000).toISOString();
}

/** UTC ISO → локальна дата магазину (YYYY-MM-DD). */
export function isoToKyivDate(iso: string | null | undefined): string {
  if (!iso) {
    return '';
  }
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : kyivDate(date);
}

/** UTC ISO → локальний час магазину «гг:хв». */
export function formatTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: KYIV_TZ,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date);
}

/** Секунди тривалості → «хв с» для журналу синхронізації. */
export function formatSeconds(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) {
    return '—';
  }
  return formatDuration(Math.round(seconds * 1000));
}
