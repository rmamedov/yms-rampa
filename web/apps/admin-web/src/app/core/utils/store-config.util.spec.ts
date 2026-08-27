import { CalendarException, DayOfWeek, Ramp, ReceivingWindow } from '../models';
import {
  ConfigFormState,
  CONFIG_DEFAULTS,
  countDailySlots,
  effectiveIntervals,
  emptyReceivingWindows,
  generateSlotStarts,
  minimumEffectiveDate,
  ReservedRuleCandidate,
  validateDayIntervals,
  validateEffectiveDate,
  validateException,
  validateRamps,
  validateReservedRule,
} from './store-config.util';
import { addDays } from './time.util';

function ramp(overrides: Partial<Ramp> = {}): Ramp {
  return { id: 'r1', number: 1, name: 'Основна', enabled: true, ...overrides };
}

function windows(): ReceivingWindow[] {
  return emptyReceivingWindows().map((w) =>
    w.dayOfWeek <= 5
      ? { dayOfWeek: w.dayOfWeek, intervals: [{ from: '08:00', to: '12:00' }] }
      : w,
  );
}

function config(overrides: Partial<ConfigFormState> = {}): ConfigFormState {
  return {
    slotSizeMinutes: 30,
    ramps: [ramp()],
    maxVehicleWeightTons: 20,
    leadTimeMinutes: CONFIG_DEFAULTS.leadTimeMinutes,
    bookingHorizonDays: CONFIG_DEFAULTS.bookingHorizonDays,
    noShowGraceMinutes: CONFIG_DEFAULTS.noShowGraceMinutes,
    holdMaxMinutes: CONFIG_DEFAULTS.holdMaxMinutes,
    receivingWindows: windows(),
    calendarExceptions: [],
    ...overrides,
  };
}

describe('STC-11 — валідація інтервалів прийому', () => {
  it('початок має бути раніше за кінець', () => {
    const errors = validateDayIntervals(1 as DayOfWeek, [{ from: '18:00', to: '08:00' }], 30);
    expect(errors[0].messageKey).toBe('receiving.error.order');
  });

  it('інтервали одного дня не перетинаються', () => {
    const errors = validateDayIntervals(
      1 as DayOfWeek,
      [
        { from: '08:00', to: '12:00' },
        { from: '11:00', to: '15:00' },
      ],
      30,
    );
    expect(errors.map((e) => e.messageKey)).toContain('receiving.error.overlap');
  });

  it('суміжні інтервали без перетину — валідні', () => {
    const errors = validateDayIntervals(
      1 as DayOfWeek,
      [
        { from: '08:00', to: '12:00' },
        { from: '12:00', to: '15:00' },
      ],
      30,
    );
    expect(errors).toEqual([]);
  });

  it('крок часу — 5 хвилин', () => {
    const errors = validateDayIntervals(2 as DayOfWeek, [{ from: '08:03', to: '12:00' }], 30);
    expect(errors[0].messageKey).toBe('receiving.error.step');
  });

  it('інтервал коротший за розмір слоту відхиляється', () => {
    const errors = validateDayIntervals(3 as DayOfWeek, [{ from: '08:00', to: '08:20' }], 30);
    expect(errors[0].messageKey).toBe('receiving.error.shorterThanSlot');
    expect(errors[0].params).toEqual({ slot: 30 });
  });
});

describe('STC-23 — генерація сітки слотів', () => {
  it('неповний «хвіст» вікна не генерується', () => {
    const starts = generateSlotStarts([{ from: '08:00', to: '09:20' }], 30);
    expect(starts).toEqual(['08:00', '08:30']);
  });

  it('кілька інтервалів × активні рампи', () => {
    const count = countDailySlots(
      [
        { from: '08:00', to: '10:00' },
        { from: '11:00', to: '12:00' },
      ],
      30,
      3,
    );
    expect(count).toBe((4 + 2) * 3);
  });

  it('виняток «вихідний» має пріоритет над тижневим шаблоном', () => {
    const date = '2026-09-07'; // понеділок
    const exception: CalendarException = {
      id: `exc-${date}`,
      date,
      type: 'closed',
      intervals: [],
      reason: 'Свято',
    };
    expect(effectiveIntervals(windows(), [exception], date)).toEqual([]);
    expect(effectiveIntervals(windows(), [], date)).toHaveLength(1);
  });
});

describe('STC-12/13 — календар винятків', () => {
  const today = '2026-08-27';

  it('виняток у минулому відхиляється', () => {
    const errors = validateException(
      { id: 'e', date: '2026-08-20', type: 'closed', intervals: [], reason: 'Свято' },
      [],
      30,
      today,
    );
    expect(errors).toContain('receiving.error.pastDate');
  });

  it('виняток далі ніж на 365 днів відхиляється', () => {
    const errors = validateException(
      {
        id: 'e',
        date: addDays(today, 400),
        type: 'closed',
        intervals: [],
        reason: 'Свято',
      },
      [],
      30,
      today,
    );
    expect(errors).toContain('receiving.error.horizon');
  });

  it('причина обовʼязкова', () => {
    const errors = validateException(
      { id: 'e', date: addDays(today, 10), type: 'closed', intervals: [], reason: '  ' },
      [],
      30,
      today,
    );
    expect(errors).toContain('receiving.error.reason');
  });

  it('змінений графік потребує щонайменше одного інтервалу', () => {
    const errors = validateException(
      {
        id: 'e',
        date: addDays(today, 10),
        type: 'custom',
        intervals: [],
        reason: 'Скорочений день',
      },
      [],
      30,
      today,
    );
    expect(errors).toContain('receiving.error.customEmpty');
  });

  it('коректний виняток проходить без помилок', () => {
    const errors = validateException(
      {
        id: 'e',
        date: addDays(today, 10),
        type: 'custom',
        intervals: [{ from: '09:00', to: '13:00' }],
        reason: 'Скорочений день',
      },
      [],
      30,
      today,
    );
    expect(errors).toEqual([]);
  });
});

describe('STC-21 — рампи', () => {
  it('номери рамп мають бути унікальні', () => {
    expect(validateRamps([ramp({ id: 'a' }), ramp({ id: 'b' })])).toContain(
      'slots.error.rampNumber',
    );
  });

  it('порожній перелік рамп — помилка', () => {
    expect(validateRamps([])).toContain('slots.error.noRamps');
  });
});

describe('STC-42 — правила резерву', () => {
  const base: ReservedRuleCandidate = {
    id: 'res-1',
    supplierId: 'sup-1',
    rampId: 'r1',
    slotStartTime: '08:00',
    dayOfWeek: 1,
    date: null,
    validFrom: '2026-09-01',
    validTo: null,
  };
  const context = () => {
    const cfg = config();
    return {
      ramps: cfg.ramps,
      receivingWindows: cfg.receivingWindows,
      slotSizeMinutes: cfg.slotSizeMinutes,
    };
  };

  it('коректне правило валідне', () => {
    expect(validateReservedRule(base, context(), [])).toEqual([]);
  });

  it('час поза вікном прийому відхиляється', () => {
    expect(
      validateReservedRule({ ...base, slotStartTime: '20:00' }, context(), []),
    ).toContain('reserves.error.outsideWindow');
  });

  it('вимкнена рампа відхиляється', () => {
    const cfg = config({ ramps: [ramp({ enabled: false })] });
    expect(
      validateReservedRule(base, {
        ramps: cfg.ramps,
        receivingWindows: cfg.receivingWindows,
        slotSizeMinutes: cfg.slotSizeMinutes,
      }, []),
    ).toContain('reserves.error.rampDisabled');
  });

  it('одночасно день тижня і дата — помилка', () => {
    expect(
      validateReservedRule({ ...base, date: '2026-09-07' }, context(), []),
    ).toContain('reserves.error.mode');
  });

  it('перетин двох правил на один слот заборонений', () => {
    expect(
      validateReservedRule({ ...base, id: 'res-2' }, context(), [
        { ...base, active: true },
      ]),
    ).toContain('reserves.error.overlap');
  });
});

describe('STC-60 — дата набрання чинності', () => {
  const today = '2026-08-27';

  it('для наступних версій мінімальна дата — завтра', () => {
    expect(minimumEffectiveDate(false, today)).toBe('2026-08-28');
    expect(validateEffectiveDate(today, false, today)).toBe(
      'conflicts.error.effectiveFrom',
    );
    expect(validateEffectiveDate('2026-08-26', false, today)).toBe(
      'conflicts.error.effectiveFrom',
    );
    expect(validateEffectiveDate('2026-08-28', false, today)).toBeNull();
  });

  it('для ПЕРШОЇ версії бекенд дозволяє сьогоднішню дату', () => {
    expect(minimumEffectiveDate(true, today)).toBe(today);
    expect(validateEffectiveDate(today, true, today)).toBeNull();
    expect(validateEffectiveDate('2026-08-26', true, today)).toBe(
      'conflicts.error.effectiveFrom',
    );
  });
});
