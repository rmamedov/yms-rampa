/**
 * Робота з часом у Europe/Kyiv (SUP-UX-03): усі дати зберігаються в UTC,
 * відображаються і нарізаються в київському часі, формат 24-годинний.
 */
export const KYIV_TZ = 'Europe/Kyiv';

const PARTS_FORMATTER = new Intl.DateTimeFormat('en-GB', {
  timeZone: KYIV_TZ,
  hour12: false,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
});

interface KyivParts {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
}

function kyivParts(instant: Date): KyivParts {
  const parts = PARTS_FORMATTER.formatToParts(instant);
  const get = (type: Intl.DateTimeFormatPartTypes): number => {
    const found = parts.find((p) => p.type === type)?.value ?? '0';
    return Number(found);
  };
  // 24:00 трапляється у деяких реалізаціях ICU для півночі.
  const hour = get('hour') % 24;
  return {
    year: get('year'),
    month: get('month'),
    day: get('day'),
    hour,
    minute: get('minute'),
    second: get('second'),
  };
}

/**
 * UTC ISO 8601 у тому ж вигляді, у якому його віддає бекенд:
 * `Y-m-d\TH:i:s\Z`, без мілісекунд (DATA-01).
 */
export function utcIso(instant: Date): string {
  return `${instant.toISOString().slice(0, 19)}Z`;
}

/** Зсув Києва відносно UTC у хвилинах на конкретний момент (враховує DST). */
export function kyivOffsetMinutes(instant: Date): number {
  const p = kyivParts(instant);
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
  return (asUtc - instant.getTime()) / 60000;
}

/** «YYYY-MM-DD» + «HH:mm» у київському часі → момент у UTC. */
export function kyivToUtc(dateIso: string, timeHm: string): Date {
  const [y, m, d] = dateIso.split('-').map(Number);
  const [hh, mm] = timeHm.split(':').map(Number);
  const naive = Date.UTC(y, m - 1, d, hh, mm, 0);
  let offset = kyivOffsetMinutes(new Date(naive));
  let ts = naive - offset * 60000;
  // Друга ітерація прибирає похибку на межі переводу годинника.
  offset = kyivOffsetMinutes(new Date(ts));
  ts = naive - offset * 60000;
  return new Date(ts);
}

/** Дата у Києві як «YYYY-MM-DD». */
export function kyivDateIso(instant: Date): string {
  const p = kyivParts(instant);
  return `${pad(p.year, 4)}-${pad(p.month, 2)}-${pad(p.day, 2)}`;
}

/** Час у Києві як «HH:mm». */
export function kyivTimeHm(instant: Date): string {
  const p = kyivParts(instant);
  return `${pad(p.hour, 2)}:${pad(p.minute, 2)}`;
}

const WEEKDAYS = ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
const MONTHS_GEN = [
  'січня',
  'лютого',
  'березня',
  'квітня',
  'травня',
  'червня',
  'липня',
  'серпня',
  'вересня',
  'жовтня',
  'листопада',
  'грудня',
];

/** «12 березня» — короткий підпис дати у стрічці. */
export function kyivDayLabel(dateIso: string): string {
  const [, m, d] = dateIso.split('-').map(Number);
  return `${d} ${MONTHS_GEN[m - 1]}`;
}

/** Скорочення дня тижня у київському часі. */
export function kyivWeekdayLabel(dateIso: string): string {
  const noon = kyivToUtc(dateIso, '12:00');
  const weekday = new Intl.DateTimeFormat('en-US', {
    timeZone: KYIV_TZ,
    weekday: 'short',
  }).format(noon);
  const index = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(
    weekday,
  );
  return WEEKDAYS[index < 0 ? 0 : index];
}

/** «12 березня 2026» — повний підпис дати. */
export function kyivFullDateLabel(dateIso: string): string {
  const [y, m, d] = dateIso.split('-').map(Number);
  return `${d} ${MONTHS_GEN[m - 1]} ${y}`;
}

/** Додає дні до дати «YYYY-MM-DD» у календарному сенсі. */
export function addDays(dateIso: string, days: number): string {
  const [y, m, d] = dateIso.split('-').map(Number);
  const base = new Date(Date.UTC(y, m - 1, d));
  base.setUTCDate(base.getUTCDate() + days);
  return kyivDateIsoFromUtcMidnight(base);
}

function kyivDateIsoFromUtcMidnight(date: Date): string {
  return `${pad(date.getUTCFullYear(), 4)}-${pad(
    date.getUTCMonth() + 1,
    2,
  )}-${pad(date.getUTCDate(), 2)}`;
}

/** Різниця в календарних днях між двома «YYYY-MM-DD». */
export function diffDays(fromIso: string, toIso: string): number {
  const [fy, fm, fd] = fromIso.split('-').map(Number);
  const [ty, tm, td] = toIso.split('-').map(Number);
  const from = Date.UTC(fy, fm - 1, fd);
  const to = Date.UTC(ty, tm - 1, td);
  return Math.round((to - from) / 86400000);
}

/** «mm:ss» для таймера холду. */
export function formatCountdown(totalSeconds: number): string {
  const safe = Math.max(0, Math.floor(totalSeconds));
  const minutes = Math.floor(safe / 60);
  const seconds = safe % 60;
  return `${pad(minutes, 2)}:${pad(seconds, 2)}`;
}

function pad(value: number, size: number): string {
  return String(value).padStart(size, '0');
}
