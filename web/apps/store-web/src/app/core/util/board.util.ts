import { Booking, BookingStatus } from '../models/booking.model';
import { Ramp } from '../models/store.model';
import { kyivMinutesOfDay, minutesBetween } from './date.util';

// ---------------------------------------------------------------------------
// Фільтри (STW-23)
// ---------------------------------------------------------------------------

export interface BoardFilters {
  readonly rampIds: readonly string[];
  readonly supplierQuery: string;
  readonly statuses: readonly BookingStatus[];
  readonly onlyDelayed: boolean;
  readonly onlyWalkIn: boolean;
}

export const EMPTY_FILTERS: BoardFilters = {
  rampIds: [],
  supplierQuery: '',
  statuses: [],
  onlyDelayed: false,
  onlyWalkIn: false,
};

export function activeFilterCount(filters: BoardFilters): number {
  let count = 0;
  if (filters.rampIds.length) count += 1;
  if (filters.supplierQuery.trim()) count += 1;
  if (filters.statuses.length) count += 1;
  if (filters.onlyDelayed) count += 1;
  if (filters.onlyWalkIn) count += 1;
  return count;
}

/** Комбінація фільтрів за логікою AND. */
export function applyFilters(
  bookings: readonly Booking[],
  filters: BoardFilters,
): Booking[] {
  const query = filters.supplierQuery.trim().toLocaleLowerCase('uk-UA');
  return bookings.filter((b) => {
    if (filters.rampIds.length && !filters.rampIds.includes(b.rampId)) {
      return false;
    }
    if (filters.statuses.length && !filters.statuses.includes(b.status)) {
      return false;
    }
    if (filters.onlyDelayed && !b.delayed.flag) {
      return false;
    }
    if (filters.onlyWalkIn && b.type !== 'walk_in') {
      return false;
    }
    if (
      query &&
      !b.supplierNameSnapshot.toLocaleLowerCase('uk-UA').includes(query)
    ) {
      return false;
    }
    return true;
  });
}

// ---------------------------------------------------------------------------
// Денна зведена статистика (STW-24)
// ---------------------------------------------------------------------------

export interface DailyStats {
  readonly total: number;
  readonly arrived: number;
  readonly completed: number;
  readonly noShow: number;
  readonly rejected: number;
  readonly walkIn: number;
  /** Середнє unloadingStartedAt − arrivedAt по completed, хв; null якщо немає. */
  readonly avgWaitMinutes: number | null;
}

export function computeDailyStats(bookings: readonly Booking[]): DailyStats {
  let arrived = 0;
  let completed = 0;
  let noShow = 0;
  let rejected = 0;
  let walkIn = 0;
  let waitSum = 0;
  let waitCount = 0;

  for (const b of bookings) {
    if (
      b.status === 'arrived' ||
      b.status === 'unloading' ||
      b.status === 'completed'
    ) {
      arrived += 1;
    }
    if (b.status === 'completed') {
      completed += 1;
      const wait = minutesBetween(b.arrivedAt, b.unloadingStartedAt);
      if (wait !== null && wait >= 0) {
        waitSum += wait;
        waitCount += 1;
      }
    }
    if (b.status === 'no_show') noShow += 1;
    if (b.status === 'rejected') rejected += 1;
    if (b.type === 'walk_in') walkIn += 1;
  }

  return {
    total: bookings.length,
    arrived,
    completed,
    noShow,
    rejected,
    walkIn,
    avgWaitMinutes: waitCount ? Math.round(waitSum / waitCount) : null,
  };
}

// ---------------------------------------------------------------------------
// Overrun / «під ризиком» (STW-40)
// ---------------------------------------------------------------------------

export interface RiskState {
  /** Рампи, на яких зараз є overrun. */
  readonly overrunRampIds: readonly string[];
  /** Бронювання, що перевищили слот (id). */
  readonly overrunBookingIds: readonly string[];
  /** Наступні бронювання цих рамп — «під ризиком» (id). */
  readonly atRiskBookingIds: readonly string[];
  /** Перевищення в хвилинах для кожного overrun-бронювання. */
  readonly overrunMinutes: Readonly<Record<string, number>>;
}

export const EMPTY_RISK: RiskState = {
  overrunRampIds: [],
  overrunBookingIds: [],
  atRiskBookingIds: [],
  overrunMinutes: {},
};

export function computeRiskState(
  bookings: readonly Booking[],
  nowIso: string,
): RiskState {
  const nowMs = new Date(nowIso).getTime();
  const overrunRampIds = new Set<string>();
  const overrunBookingIds: string[] = [];
  const overrunMinutes: Record<string, number> = {};
  const rampOverrunEnd = new Map<string, number>();

  for (const b of bookings) {
    if (b.status !== 'unloading') continue;
    const slotEndMs = new Date(b.slotEnd).getTime();
    if (nowMs <= slotEndMs) continue;
    overrunRampIds.add(b.rampId);
    overrunBookingIds.push(b.id);
    overrunMinutes[b.id] = Math.round((nowMs - slotEndMs) / 60_000);
    const previous = rampOverrunEnd.get(b.rampId);
    if (previous === undefined || slotEndMs < previous) {
      rampOverrunEnd.set(b.rampId, slotEndMs);
    }
  }

  const atRiskBookingIds: string[] = [];
  for (const b of bookings) {
    const threshold = rampOverrunEnd.get(b.rampId);
    if (threshold === undefined) continue;
    if (b.status !== 'booked' && b.status !== 'arrived') continue;
    if (new Date(b.slotStart).getTime() >= threshold) {
      atRiskBookingIds.push(b.id);
    }
  }

  return {
    overrunRampIds: [...overrunRampIds],
    overrunBookingIds,
    atRiskBookingIds,
    overrunMinutes,
  };
}

// ---------------------------------------------------------------------------
// Групування для дошки за рампами (STW-06)
// ---------------------------------------------------------------------------

export interface RampColumn {
  readonly ramp: Ramp;
  readonly bookings: readonly Booking[];
  readonly atRisk: boolean;
}

export function groupByRamp(
  bookings: readonly Booking[],
  ramps: readonly Ramp[],
  risk: RiskState = EMPTY_RISK,
): RampColumn[] {
  return ramps.map((ramp) => ({
    ramp,
    bookings: bookings
      .filter((b) => b.rampId === ramp.rampId)
      .sort(
        (a, b) =>
          new Date(a.slotStart).getTime() - new Date(b.slotStart).getTime(),
      ),
    atRisk: risk.overrunRampIds.includes(ramp.rampId),
  }));
}

/** Мобільний список — плоский, відсортований за часом слоту (STW-30). */
export function sortBySlotStart(bookings: readonly Booking[]): Booking[] {
  return [...bookings].sort((a, b) => {
    const diff =
      new Date(a.slotStart).getTime() - new Date(b.slotStart).getTime();
    return diff !== 0 ? diff : a.rampId.localeCompare(b.rampId);
  });
}

// ---------------------------------------------------------------------------
// Таймлайн (STW-06)
// ---------------------------------------------------------------------------

export interface TimelineBounds {
  /** Хвилини від початку київської доби. */
  readonly startMinutes: number;
  readonly endMinutes: number;
}

export interface TimelinePlacement {
  readonly bookingId: string;
  /** Відсоток від лівого краю. */
  readonly leftPercent: number;
  readonly widthPercent: number;
}

export function computeTimelineBounds(
  intervals: readonly { from: string; to: string }[],
  fallback: TimelineBounds = { startMinutes: 8 * 60, endMinutes: 20 * 60 },
): TimelineBounds {
  if (!intervals.length) return fallback;
  let start = Number.POSITIVE_INFINITY;
  let end = Number.NEGATIVE_INFINITY;
  for (const interval of intervals) {
    const [fh, fm] = interval.from.split(':').map(Number);
    const [th, tm] = interval.to.split(':').map(Number);
    start = Math.min(start, fh * 60 + fm);
    end = Math.max(end, th * 60 + tm);
  }
  return { startMinutes: start, endMinutes: end };
}

export function placeOnTimeline(
  booking: Booking,
  bounds: TimelineBounds,
): TimelinePlacement {
  const span = Math.max(1, bounds.endMinutes - bounds.startMinutes);
  const rawStart = kyivMinutesOfDay(booking.slotStart);
  const rawEnd = kyivMinutesOfDay(booking.slotEnd);
  const end = rawEnd <= rawStart ? rawStart + 30 : rawEnd;

  const clampedStart = Math.max(bounds.startMinutes, Math.min(rawStart, bounds.endMinutes));
  const clampedEnd = Math.max(clampedStart, Math.min(end, bounds.endMinutes));

  const leftPercent = ((clampedStart - bounds.startMinutes) / span) * 100;
  const widthPercent = Math.max(
    2,
    ((clampedEnd - clampedStart) / span) * 100,
  );
  return {
    bookingId: booking.id,
    leftPercent: round2(leftPercent),
    widthPercent: round2(Math.min(widthPercent, 100 - leftPercent)),
  };
}

export function timelineTicks(bounds: TimelineBounds, stepMinutes = 60): number[] {
  const ticks: number[] = [];
  for (let m = bounds.startMinutes; m <= bounds.endMinutes; m += stepMinutes) {
    ticks.push(m);
  }
  return ticks;
}

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}
