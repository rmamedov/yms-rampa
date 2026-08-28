import {
  CalendarException,
  DayOfWeek,
  Ramp,
  ReceivingWindow,
  SlotSizeMinutes,
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
import {
  validateHoldMax,
  validateHorizon,
  validateLeadTime,
  validateMaxWeight,
  validateNoShowGrace,
  validateReason,
} from './validators.util';

/**
 * Стан форми конфігурації магазину до відправки.
 * Поля можуть бути порожні, доки користувач не заповнив вкладки; при збереженні
 * бекенд вимагає slotSizeMinutes і maxVehicleWeightTons (requireInt/requireFloat).
 */
export interface ConfigFormState {
  readonly slotSizeMinutes: SlotSizeMinutes | null;
  readonly ramps: readonly Ramp[];
  readonly maxVehicleWeightTons: number | null;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly noShowGraceMinutes: number;
  readonly holdMaxMinutes: number;
  readonly receivingWindows: readonly ReceivingWindow[];
  readonly calendarExceptions: readonly CalendarException[];
}

/** StoreConfiguration::LEAD_TIME_DEFAULT та інші типові значення бекенду. */
export const CONFIG_DEFAULTS = {
  leadTimeMinutes: 60,
  bookingHorizonDays: 14,
  noShowGraceMinutes: 30,
  holdMaxMinutes: 15,
} as const;

export interface IntervalError {
  readonly dayOfWeek: DayOfWeek;
  readonly index: number;
  readonly messageKey: string;
  readonly params?: Record<string, string | number>;
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
  // Ключ винятку в бекенді — дата, тож id похідний від неї (mapCalendarException):
  // порівняння за id завжди збігалося б із порівнянням за датою і дублікат
  // проходив би непоміченим. Самого себе (редагування наявного) відсікаємо
  // за посиланням на обʼєкт, а не за id.
  if (existing.some((e) => e !== exception && e.date === exception.date)) {
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
  windows: readonly ReceivingWindow[],
  exceptions: readonly CalendarException[],
  date: string,
): readonly TimeInterval[] {
  const exception = exceptions.find((e) => e.date === date);
  if (exception) {
    return exception.type === 'closed' ? [] : exception.intervals;
  }
  const day = dayOfWeek(date) as DayOfWeek;
  return windows.find((w) => w.dayOfWeek === day)?.intervals ?? [];
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

/** Кандидат у правило резерву — форма вкладки «Резерви» до відправки. */
export interface ReservedRuleCandidate {
  readonly id?: string;
  readonly supplierId: string;
  readonly rampId: string;
  readonly slotStartTime: string;
  readonly dayOfWeek: DayOfWeek | null;
  readonly date: string | null;
  readonly validFrom: string;
  readonly validTo: string | null;
}

/**
 * STC-42: резерв лише у вікні прийому, на увімкнену рампу, без перетину правил.
 * Ті самі перевірки робить ReservedSlotRuleService — тут це попередній фільтр,
 * щоб не ходити на бекенд за очевидною помилкою.
 */
export function validateReservedRule(
  rule: ReservedRuleCandidate,
  context: {
    readonly ramps: readonly Ramp[];
    readonly receivingWindows: readonly ReceivingWindow[];
    readonly slotSizeMinutes: SlotSizeMinutes | null;
  },
  existing: ReadonlyArray<ReservedRuleCandidate & { active: boolean }>,
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
  const ramp = context.ramps.find((r) => r.id === rule.rampId);
  if (!ramp || !ramp.enabled) {
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
      context.receivingWindows,
      day,
      rule.slotStartTime,
      context.slotSizeMinutes,
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


export function emptyReceivingWindows(): ReceivingWindow[] {
  return ([1, 2, 3, 4, 5, 6, 7] as DayOfWeek[]).map((dow) => ({
    dayOfWeek: dow,
    intervals: [],
  }));
}

/**
 * Доповнює перелік вікон усіма сімома днями тижня (порожніми, якщо вікон немає).
 * Бекенд віддає лише дні, для яких вікна задані, а форма редагує день як
 * наявний рядок: без цього доповнення «Додати інтервал» для дня, якого немає
 * в конфігурації, мовчки нічого не робить.
 */
export function normalizeReceivingWindows(
  windows: readonly ReceivingWindow[],
): ReceivingWindow[] {
  return emptyReceivingWindows().map((empty) => ({
    dayOfWeek: empty.dayOfWeek,
    intervals: (
      windows.find((w) => w.dayOfWeek === empty.dayOfWeek)?.intervals ?? []
    ).map((i) => ({ ...i })),
  }));
}

/**
 * Помилки чернетки конфігурації, з якими нова версія не збережеться:
 * бракує обовʼязкових полів (requireInt/requireFloat), немає жодної рампи
 * або зламані вікна прийому. Поки список не порожній, «Зберегти» має бути
 * заблоковано — інакше користувач отримує 422 із загальним текстом.
 */
export function configBlockingErrors(draft: ConfigFormState | null): string[] {
  if (draft === null) {
    return ['store.error.configIncomplete'];
  }
  const errors: string[] = [];
  if (draft.slotSizeMinutes === null || draft.maxVehicleWeightTons === null) {
    errors.push('store.error.configIncomplete');
  }
  errors.push(...validateRamps(draft.ramps));
  errors.push(
    ...validateReceivingWindows(draft.receivingWindows, draft.slotSizeMinutes).map(
      (e) => e.messageKey,
    ),
  );

  // Межі обмежень теж блокують збереження: інакше форма пропускає значення,
  // які сервер відхилить, і користувач отримує загальне «некоректна
  // конфігурація» замість підказки біля конкретного поля.
  const limits = [
    draft.maxVehicleWeightTons === null ? null : validateMaxWeight(draft.maxVehicleWeightTons),
    validateLeadTime(draft.leadTimeMinutes),
    validateHorizon(draft.bookingHorizonDays),
    validateNoShowGrace(draft.noShowGraceMinutes),
    validateHoldMax(draft.holdMaxMinutes),
  ].filter((e): e is string => e !== null);
  errors.push(...limits);

  return [...new Set(errors)];
}
