import {
  ActionContext,
  canStoreTransition,
  evaluateAction,
  nextSwipeAction,
  normalizeCompleteForm,
  validateCompleteForm,
  validateDelayForm,
  validateRejectForm,
  validateWalkInForm,
} from './booking-rules.util';
import { makeBooking } from '../testing/booking.factory';

const NOW = '2026-08-27T08:00:00.000Z';

function ctx(overrides: Partial<ActionContext> = {}): ActionContext {
  return {
    now: NOW,
    viewDateKey: '2026-08-27',
    todayKey: '2026-08-27',
    role: 'store_operator',
    hasFreeRampForReassign: true,
    ...overrides,
  };
}

describe('Статусні переходи магазину (STW-13…16, STW-35)', () => {
  it('дозволяє лише переходи зі схеми розділу 9.4', () => {
    expect(canStoreTransition('arrived', 'unloading')).toBe(true);
    expect(canStoreTransition('arrived', 'rejected')).toBe(true);
    expect(canStoreTransition('unloading', 'completed')).toBe(true);
    expect(canStoreTransition('booked', 'no_show')).toBe(true);

    expect(canStoreTransition('booked', 'unloading')).toBe(false);
    expect(canStoreTransition('completed', 'unloading')).toBe(false);
    expect(canStoreTransition('rejected', 'completed')).toBe(false);
  });

  it('«Розвантаження почалось» доступне лише зі статусу arrived', () => {
    const arrived = makeBooking({ status: 'arrived' });
    const booked = makeBooking({ status: 'booked' });

    expect(evaluateAction(arrived, 'startUnloading', ctx()).enabled).toBe(true);
    const denied = evaluateAction(booked, 'startUnloading', ctx());
    expect(denied.enabled).toBe(false);
    expect(denied.reasonKey).toBe('action.disabled.wrongStatus');
  });

  it('«Не приїхав» блокується до кінця слоту і дозволяється після (STW-15)', () => {
    const booking = makeBooking({
      status: 'booked',
      slotStart: '2026-08-27T08:30:00.000Z',
      slotEnd: '2026-08-27T09:00:00.000Z',
    });

    const early = evaluateAction(booking, 'noShow', ctx());
    expect(early.enabled).toBe(false);
    expect(early.reasonKey).toBe('action.disabled.noShowTooEarly');

    const late = evaluateAction(
      booking,
      'noShow',
      ctx({ now: '2026-08-27T09:00:01.000Z' }),
    );
    expect(late.enabled).toBe(true);
  });

  it('минула дата read-only, крім дозакриття unloading → completed (STW-22)', () => {
    const past = ctx({ viewDateKey: '2026-08-26' });
    const unloading = makeBooking({ status: 'unloading' });
    const arrived = makeBooking({ status: 'arrived' });

    expect(evaluateAction(unloading, 'complete', past).enabled).toBe(true);
    expect(evaluateAction(arrived, 'startUnloading', past).enabled).toBe(false);
    expect(evaluateAction(arrived, 'reject', past).reasonKey).toBe(
      'action.disabled.pastDate',
    );
  });

  it('«Перевести на іншу рампу» недоступне без вільної рампи (STW-42)', () => {
    const booking = makeBooking({ status: 'arrived' });
    const denied = evaluateAction(
      booking,
      'reassign',
      ctx({ hasFreeRampForReassign: false }),
    );
    expect(denied.enabled).toBe(false);
    expect(denied.reasonKey).toBe('action.disabled.noFreeRamp');
    expect(evaluateAction(booking, 'reassign', ctx()).enabled).toBe(true);
  });

  it('свайп управо обирає наступний допустимий перехід (STW-31)', () => {
    expect(nextSwipeAction(makeBooking({ status: 'arrived' }), ctx())).toBe(
      'startUnloading',
    );
    expect(nextSwipeAction(makeBooking({ status: 'unloading' }), ctx())).toBe(
      'complete',
    );
    expect(nextSwipeAction(makeBooking({ status: 'completed' }), ctx())).toBeNull();
    expect(
      nextSwipeAction(
        makeBooking({
          status: 'booked',
          slotEnd: '2026-08-27T07:00:00.000Z',
        }),
        ctx(),
      ),
    ).toBe('noShow');
  });
});

describe('Форма підтвердження розвантаження (STW-36)', () => {
  it('недовантаження автоматично вмикає partialUnload і вимагає причину', () => {
    const value = {
      unloadedPalletsCount: 20,
      partialUnload: false,
      reason: null,
      comment: '',
    };
    expect(normalizeCompleteForm(value, 26).partialUnload).toBe(true);

    const result = validateCompleteForm(value, 26);
    expect(result.valid).toBe(false);
    expect(result.errors).toContain('complete.partialReasonRequired');
  });

  it('приймає повну кількість палет без причини', () => {
    expect(
      validateCompleteForm(
        {
          unloadedPalletsCount: 26,
          partialUnload: false,
          reason: null,
          comment: '',
        },
        26,
      ).valid,
    ).toBe(true);
  });

  it('вимагає коментар для причини «інше» і відхиляє завелику кількість', () => {
    expect(
      validateCompleteForm(
        {
          unloadedPalletsCount: 20,
          partialUnload: true,
          reason: 'other',
          comment: '   ',
        },
        26,
      ).errors,
    ).toContain('complete.commentRequired');

    expect(
      validateCompleteForm(
        {
          unloadedPalletsCount: 40,
          partialUnload: false,
          reason: null,
          comment: '',
        },
        26,
      ).errors,
    ).toContain('complete.invalidCount');
  });
});

describe('Форма відмови в прийомі (STW-35)', () => {
  it('вимагає причину з довідника', () => {
    expect(validateRejectForm({ reason: null, comment: '' }).errors).toContain(
      'reject.reasonRequired',
    );
    expect(
      validateRejectForm({ reason: 'missing_documents', comment: '' }).valid,
    ).toBe(true);
    expect(
      validateRejectForm({ reason: 'other', comment: '' }).errors,
    ).toContain('reject.commentRequired');
  });
});

describe('Форма затримки (STW-18)', () => {
  const booking = makeBooking({
    slotStart: '2026-08-27T07:00:00.000Z',
    slotEnd: '2026-08-27T07:30:00.000Z',
  });

  it('ETA має бути пізнішим за початок слоту', () => {
    const result = validateDelayForm(
      { reason: 'ramp_busy', comment: '', eta: '2026-08-27T06:30:00.000Z' },
      booking,
    );
    expect(result.valid).toBe(false);
    expect(result.errors).toContain('delay.etaBeforeSlot');
  });

  it('приймає коректну затримку і перевіряє довжину коментаря', () => {
    expect(
      validateDelayForm(
        { reason: 'ramp_busy', comment: 'затор', eta: '2026-08-27T08:15:00.000Z' },
        booking,
      ).valid,
    ).toBe(true);

    expect(
      validateDelayForm(
        {
          reason: 'ramp_busy',
          comment: 'x'.repeat(501),
          eta: '2026-08-27T08:15:00.000Z',
        },
        booking,
      ).errors,
    ).toContain('delay.commentTooLong');
  });

  it('вимагає причину та ETA', () => {
    const result = validateDelayForm(
      { reason: null, comment: '', eta: null },
      booking,
    );
    expect(result.errors).toEqual(
      expect.arrayContaining(['delay.reasonRequired', 'delay.etaRequired']),
    );
  });
});

describe('Форма walk-in (STW-37/38)', () => {
  const base = {
    supplierId: null,
    externalSupplierName: '',
    useExternalSupplier: false,
    plateNumber: '',
    weightTons: null,
    palletsCount: null,
    orderId: '',
    rampId: null,
    slotStart: null,
  };

  it('вимагає постачальника, авто, палети і слот', () => {
    const result = validateWalkInForm(base, 10);
    expect(result.valid).toBe(false);
    expect(result.errors).toEqual(
      expect.arrayContaining([
        'walkIn.supplierRequired',
        'walkIn.plateRequired',
        'walkIn.weightRequired',
        'walkIn.palletsRange',
        'walkIn.slotRequired',
      ]),
    );
  });

  it('приймає постачальника «поза системою» з вільним текстом', () => {
    const result = validateWalkInForm(
      {
        ...base,
        useExternalSupplier: true,
        externalSupplierName: 'ФОП Гуменюк',
        plateNumber: 'AA1234BB',
        weightTons: 7.5,
        palletsCount: 10,
        rampId: 'r2',
        slotStart: '2026-08-27T09:00:00.000Z',
      },
      10,
    );
    expect(result).toEqual({ valid: true, errors: [] });
  });

  it('відхиляє тоннаж понад ліміт магазину кодом VEHICLE_TOO_HEAVY', () => {
    const result = validateWalkInForm(
      {
        ...base,
        supplierId: 'sp-01',
        plateNumber: 'AA1234BB',
        weightTons: 14,
        palletsCount: 10,
        rampId: 'r2',
        slotStart: '2026-08-27T09:00:00.000Z',
      },
      10,
    );
    expect(result.errors).toContain('error.VEHICLE_TOO_HEAVY');
  });
});
