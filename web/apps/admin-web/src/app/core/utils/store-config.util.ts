import {
  CalendarException,
  DayOfWeek,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotSizeMinutes,
  StoreConfig,
  TimeInterval,
} from '../models';
import {
  addDays,
  dayOfWeek,
  diffDays,
  isOnFiveMinuteStep,
  isValidDate,
  isValidTime,
  kyivDate,
  minutesToTime,
  timeToMinutes,
} from './time.util';
import { validateMaxWeight, validateReason } from './validators.util';

export interface IntervalError {
  readonly dayOfWeek: DayOfWeek;
  readonly index: number;
  readonly messageKey: string;
  readonly params?: Record<string, string | number>;
}

/**
 * STL-04: магазин «налаштований» ⟺ задані одночасно щонайменше одне вікно прийому,
 * розмір слоту, щонайменше одна активна рампа та maxVehicleWeightTons.
 */
export function missingConfigParts(config: StoreConfig): string[] {
  const missing: string[] = [];
  const hasWindow = config.receivingWindows.some((w) => w.intervals.length > 0);
  if (!hasWindow) {
    missing.push('store.missing.windows');
  }
  if (config.slotSizeMinutes === null) {
    missing.push('store.missing.slotSize');
  }
  if (!config.ramps.some((r) => r.enabled)) {
    missing.push('store.missing.ramp');
  }
  if (validateMaxWeight(config.maxVehicleWeightTons) !== null) {
    missing.push('store.missing.weight');
  }
  return missing;
}

export function isStoreConfigured(config: StoreConfig): boolean {
  return missingConfigParts(config).length === 0;
}

/**
 * STC-11: початок < кінець; інтервали одного дня не перетинаються;
 * крок 5 хв; тривалість ≥ розміру слоту (якщо слот задано).
 */
export function validateDayIntervals(
  day: DayOfWeek,
  intervals: readonly TimeInterval[],
  slotSizeMinutes: SlotSizeMinutes | null,
): IntervalError[] {
  const errors: IntervalError[] = [];
  const ranges: Array<{ start: number; end: number; index: number }> = [];

  intervals.forEach((interval, index) => {
    if (!isValidTime(interval.from) || !isValidTime(interval.to)) {
      errors.push({ dayOfWeek: day, index, messageKey: 'receiving.error.format' });
      return;
    }
    if (!isOnFiveMinuteStep(interval.from) || !isOnFiveMinuteStep(interval.to)) {
      errors.push({ dayOfWeek: day, index, messageKey: 'receiving.error.step' });
      return;
    }
    const start = timeToMinutes(interval.from);
    const end = timeToMinutes(interval.to);
    if (start >= end) {
      errors.push({ dayOfWeek: day, index, messageKey: 'receiving.error.order' });
      return;
    }
    if (slotSizeMinutes !== null && end - start < slotSizeMinutes) {
      errors.push({
        dayOfWeek: day,
        index,
        messageKey: 'receiving.error.shorterThanSlot',
        params: { slot: slotSizeMinutes },
      });
      return;
    }
    ranges.push({ start, end, index });
  });

  const sorted = [...ranges].sort((a, b) => a.start - b.start);
  for (let i = 1; i < sorted.length; i += 1) {
    if (sorted[i].start < sorted[i - 1].end) {
      errors.push({
        dayOfWeek: day,
        index: sorted[i].index,
        messageKey: 'receiving.error.overlap',
      });
    }
  }
  return errors;
}

export function validateReceivingWindows(
  windows: readonly ReceivingWindow[],
  slotSizeMinutes: SlotSizeMinutes | null,
): IntervalError[] {
  return windows.flatMap((w) =>
    validateDayIntervals(w.dayOfWeek, w.intervals, slotSizeMinutes),
  );
}

/** STC-12, STC-13: виняток не в минулому, не далі ніж на 365 днів, з причиною. */
export function validateException(
  exception: CalendarException,
  existing: readonly CalendarException[],
  slotSizeMinutes: SlotSizeMinutes | null,
  today: string = kyivDate(),
): string[] {
  const errors: string[] = [];
  if (!isValidDate(exception.date)) {
    errors.push('receiving.error.pastDate');
    return errors;
  }
  if (diffDays(today, exception.date) < 0) {
    errors.push('receiving.error.pastDate');
  }
  if (diffDays(today, exception.date) > 365) {
    errors.push('receiving.error.horizon');
  }
  if (validateReason(exception.reason, 'receiving.error.reason') !== null) {
    errors.push('receiving.error.reason');
  }
  if (
    existing.some((e) => e.id !== exception.id && e.date === exception.date)
  ) {
    errors.push('receiving.error.duplicateDate');
  }
  if (exception.type === 'custom') {
    if (exception.intervals.length === 0) {
      errors.push('receiving.error.customEmpty');
    } else {
      const intervalErrors = validateDayIntervals(
        (dayOfWeek(exception.date) as DayOfWeek) ?? 1,
        exception.intervals,
        slotSizeMinutes,
      );
      errors.push(...intervalErrors.map((e) => e.messageKey));
    }
  }
  return [...new Set(errors)];
}

/** STC-21: номер рампи — ціле ≥1, унікальний у межах магазину. */
export function validateRamps(ramps: readonly Ramp[]): string[] {
  const errors: string[] = [];
  if (ramps.length === 0) {
    errors.push('slots.error.noRamps');
  }
  const numbers = new Set<number>();
  for (const ramp of ramps) {
    if (!Number.isInteger(ramp.number) || ramp.number < 1 || numbers.has(ramp.number)) {
      errors.push('slots.error.rampNumber');
      break;
    }
    numbers.add(ramp.number);
  }
  if (ramps.some((r) => (r.name ?? '').length > 60)) {
    errors.push('slots.error.rampName');
  }
  return [...new Set(errors)];
}

/** STC-22: рампу з історією бронювань видалити не можна — лише вимкнути. */
export function canDeleteRamp(ramp: Ramp): boolean {
  return !ramp.hasBookings;
}

/**
 * STC-23: слоти = вікна прийому × розмір слоту × активні рампи.
 * Неповний «хвіст» вікна, коротший за слот, не генерується.
 */
export function generateSlotStarts(
  intervals: readonly TimeInterval[],
  slotSizeMinutes: SlotSizeMinutes,
): string[] {
  const starts: string[] = [];
  for (const interval of intervals) {
    const from = timeToMinutes(interval.from);
    const to = timeToMinutes(interval.to);
    if (from < 0 || to < 0 || from >= to) {
      continue;
    }
    for (let t = from; t + slotSizeMinutes <= to; t += slotSizeMinutes) {
      starts.push(minutesToTime(t));
    }
  }
  return starts;
}

export function countDailySlots(
  intervals: readonly TimeInterval[],
  slotSizeMinutes: SlotSizeMinutes,
  enabledRamps: number,
): number {
  return generateSlotStarts(intervals, slotSizeMinutes).length * enabledRamps;
}

/** Інтервали прийому, чинні на конкретну дату (виняток має пріоритет, STC-12). */
export function effectiveIntervals(
  config: StoreConfig,
  date: string,
): readonly TimeInterval[] {
  const exception = config.exceptions.find((e) => e.date === date);
  if (exception) {
    return exception.type === 'closed' ? [] : exception.intervals;
  }
  const day = dayOfWeek(date) as DayOfWeek;
  return config.receivingWindows.find((w) => w.dayOfWeek === day)?.intervals ?? [];
}

/** Чи потрапляє час у якесь вікно прийому цього дня тижня. */
export function isWithinWeeklyWindow(
  windows: readonly ReceivingWindow[],
  day: DayOfWeek,
  time: string,
  slotSizeMinutes: SlotSizeMinutes | null,
): boolean {
  const intervals = windows.find((w) => w.dayOfWeek === day)?.intervals ?? [];
  const size = slotSizeMinutes ?? 0;
  const start = timeToMinutes(time);
  if (start < 0) {
    return false;
  }
  return intervals.some(
    (i) => start >= timeToMinutes(i.from) && start + size <= timeToMinutes(i.to),
  );
}

/** STC-42: резерв лише у вікні прийому, на увімкнену рампу, без перетину правил. */
export function validateReservedRule(
  rule: ReservedSlotRule,
  config: StoreConfig,
  existing: readonly ReservedSlotRule[],
): string[] {
  const errors: string[] = [];
  if (!rule.supplierId) {
    errors.push('reserves.error.supplier');
  }
  const hasDay = rule.dayOfWeek !== null;
  const hasDate = rule.date !== null && rule.date !== '';
  if (hasDay === hasDate) {
    errors.push('reserves.error.mode');
  }
  const ramp = config.ramps.find((r) => r.id === rule.rampId);
  if (!ramp) {
    errors.push('reserves.error.rampDisabled');
  } else if (!ramp.enabled) {
    errors.push('reserves.error.rampDisabled');
  }
  const day = hasDay
    ? (rule.dayOfWeek as DayOfWeek)
    : hasDate
      ? (dayOfWeek(rule.date as string) as DayOfWeek)
      : null;
  if (
    day !== null &&
    !isWithinWeeklyWindow(
      config.receivingWindows,
      day,
      rule.slotStartTime,
      config.slotSizeMinutes,
    )
  ) {
    errors.push('reserves.error.outsideWindow');
  }
  if (
    rule.validTo !== null &&
    rule.validTo !== '' &&
    diffDays(rule.validFrom, rule.validTo) < 0
  ) {
    errors.push('reserves.error.validRange');
  }
  const overlaps = existing.some(
    (other) =>
      other.id !== rule.id &&
      other.active &&
      other.rampId === rule.rampId &&
      other.slotStartTime === rule.slotStartTime &&
      ((hasDay && other.dayOfWeek === rule.dayOfWeek) ||
        (hasDate && other.date === rule.date)),
  );
  if (overlaps) {
    errors.push('reserves.error.overlap');
  }
  return [...new Set(errors)];
}

/** STC-60: зміни сітки слотів набирають чинності не раніше завтра. */
export function minimumEffectiveDate(today: string = kyivDate()): string {
  return addDays(today, 1);
}

export function validateEffectiveDate(
  date: string,
  today: string = kyivDate(),
): string | null {
  if (!isValidDate(date) || diffDays(today, date) < 1) {
    return 'conflicts.error.effectiveFrom';
  }
  return null;
}

export function emptyReceivingWindows(): ReceivingWindow[] {
  return ([1, 2, 3, 4, 5, 6, 7] as DayOfWeek[]).map((dow) => ({
    dayOfWeek: dow,
    intervals: [],
  }));
}
