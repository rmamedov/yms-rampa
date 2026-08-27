import {
  CalendarException,
  DayOfWeek,
  Ramp,
  ReservedSlotRule,
  StoreConfig,
} from '../models';
import {
  canDeleteRamp,
  countDailySlots,
  effectiveIntervals,
  emptyReceivingWindows,
  generateSlotStarts,
  isStoreConfigured,
  minimumEffectiveDate,
  missingConfigParts,
  validateDayIntervals,
  validateEffectiveDate,
  validateException,
  validateRamps,
  validateReservedRule,
} from './store-config.util';
import { addDays } from './time.util';

function ramp(overrides: Partial<Ramp> = {}): Ramp {
  return {
    id: 'r1',
    number: 1,
    name: 'Основна',
    enabled: true,
    disabledFrom: null,
    hasBookings: false,
    ...overrides,
  };
}

function config(overrides: Partial<StoreConfig> = {}): StoreConfig {
  return {
    slotSizeMinutes: 30,
    ramps: [ramp()],
    maxVehicleWeightTons: 20,
    leadTimeHours: 4,
    bookingHorizonDays: 21,
    receivingWindows: emptyReceivingWindows().map((w) =>
      w.dayOfWeek <= 5
        ? { dayOfWeek: w.dayOfWeek, intervals: [{ from: '08:00', to: '12:00' }] }
        : w,
    ),
    exceptions: [],
    reservedRules: [],
    slotBlocks: [],
    ...overrides,
  };
}

describe('STL-04 — ознака «налаштовано»', () => {
  it('магазин налаштований лише за наявності всіх чотирьох параметрів', () => {
    expect(isStoreConfigured(config())).toBe(true);
  });

  it('перелічує всі відсутні параметри', () => {
    const broken = config({
      slotSizeMinutes: null,
      maxVehicleWeightTons: null,
      ramps: [],
      receivingWindows: emptyReceivingWindows(),
    });
    expect(missingConfigParts(broken)).toEqual([
      'store.missing.windows',
      'store.missing.slotSize',
      'store.missing.ramp',
      'store.missing.weight',
    ]);
    expect(isStoreConfigured(broken)).toBe(false);
  });

  it('вимкнена рампа не рахується активною', () => {
    const disabled = config({ ramps: [ramp({ enabled: false })] });
    expect(missingConfigParts(disabled)).toContain('store.missing.ramp');
  });
});

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
    const date = addDays('2026-09-07', 0); // понеділок
    const exception: CalendarException = {
      id: 'e1',
      date,
      type: 'closed',
      intervals: [],
      reason: 'Свято',
    };
    expect(effectiveIntervals(config({ exceptions: [exception] }), date)).toEqual([]);
    expect(effectiveIntervals(config(), date)).toHaveLength(1);
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

describe('STC-21/22 — рампи', () => {
  it('номери рамп мають бути унікальні', () => {
    expect(validateRamps([ramp({ id: 'a' }), ramp({ id: 'b' })])).toContain(
      'slots.error.rampNumber',
    );
  });

  it('рампу з історією бронювань видалити не можна', () => {
    expect(canDeleteRamp(ramp({ hasBookings: true }))).toBe(false);
    expect(canDeleteRamp(ramp({ hasBookings: false }))).toBe(true);
  });
});

describe('STC-42 — правила резерву', () => {
  const base: ReservedSlotRule = {
    id: 'res-1',
    supplierId: 'sup-1',
    dayOfWeek: 1,
    date: null,
    slotStartTime: '08:00',
    rampId: 'r1',
    validFrom: '2026-09-01',
    validTo: null,
    active: true,
  };

  it('коректне правило валідне', () => {
    expect(validateReservedRule(base, config(), [])).toEqual([]);
  });

  it('час поза вікном прийому відхиляється', () => {
    expect(
      validateReservedRule({ ...base, slotStartTime: '20:00' }, config(), []),
    ).toContain('reserves.error.outsideWindow');
  });

  it('вимкнена рампа відхиляється', () => {
    const cfg = config({ ramps: [ramp({ enabled: false })] });
    expect(validateReservedRule(base, cfg, [])).toContain('reserves.error.rampDisabled');
  });

  it('одночасно день тижня і дата — помилка', () => {
    expect(
      validateReservedRule({ ...base, date: '2026-09-07' }, config(), []),
    ).toContain('reserves.error.mode');
  });

  it('перетин двох правил на один слот заборонений', () => {
    expect(
      validateReservedRule({ ...base, id: 'res-2' }, config(), [base]),
    ).toContain('reserves.error.overlap');
  });
});

describe('STC-60 — дата набрання чинності', () => {
  it('мінімальна дата — завтра', () => {
    expect(minimumEffectiveDate('2026-08-27')).toBe('2026-08-28');
  });

  it('сьогодні і минуле відхиляються', () => {
    expect(validateEffectiveDate('2026-08-27', '2026-08-27')).toBe(
      'conflicts.error.effectiveFrom',
    );
    expect(validateEffectiveDate('2026-08-26', '2026-08-27')).toBe(
      'conflicts.error.effectiveFrom',
    );
    expect(validateEffectiveDate('2026-08-28', '2026-08-27')).toBeNull();
  });
});
