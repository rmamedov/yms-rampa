import { MockBackend } from './mock-backend.service';
import { MOCK_USERS } from '../fixtures/mock-data';
import { AppError } from '../models/problem.model';
import { Booking, WalkInPayload } from '../models/booking.model';
import { computeDailyStats } from '../util/board.util';
import { toKyivDateKey } from '../util/date.util';

/** Четвер, 13:00 за Києвом — усередині вікна прийому 08:00–20:00. */
const NOW = '2026-08-27T10:00:00.000Z';
const STORE_ID = MOCK_USERS[0].stores[0].storeId;
const TODAY = toKyivDateKey(NOW);

function expectProblem(fn: () => unknown, code: string, status: number): void {
  try {
    fn();
    throw new Error(`Очікували помилку ${code}, але її не було`);
  } catch (error) {
    expect(error).toBeInstanceOf(AppError);
    expect((error as AppError).code).toBe(code);
    expect((error as AppError).status).toBe(status);
  }
}

describe('MockBackend — автентифікація', () => {
  let backend: MockBackend;

  beforeEach(() => {
    backend = new MockBackend();
    backend.clock = () => NOW;
  });

  it('видає токени і профіль із закріпленими магазинами', () => {
    const result = backend.login({
      email: 'operator@silpo.ua',
      password: 'demo',
    });
    expect(result.tokens.accessToken).toContain('mock-access.');
    expect(result.profile.role).toBe('store_operator');
    expect(result.profile.stores.length).toBeGreaterThan(1);
  });

  it('менеджер має власний набір магазинів, а адмін — жодного (STW-01/02)', () => {
    expect(
      backend.login({ email: 'manager@silpo.ua', password: 'x' }).profile.role,
    ).toBe('store_manager');
    expect(
      backend.login({ email: 'admin@silpo.ua', password: 'x' }).profile.stores,
    ).toEqual([]);
  });

  it('відхиляє порожні облікові дані і невалідний refresh', () => {
    expectProblem(
      () => backend.login({ email: '', password: '' }),
      'AUTH_INVALID_CREDENTIALS',
      401,
    );
    expectProblem(() => backend.refresh('garbage'), 'AUTH_TOKEN_INVALID', 401);
  });
});

describe('MockBackend — дошка і дії магазину', () => {
  let backend: MockBackend;

  beforeEach(() => {
    backend = new MockBackend();
    backend.clock = () => NOW;
    backend.login({ email: 'operator@silpo.ua', password: 'demo' });
  });

  function walkIn(overrides: Partial<WalkInPayload> = {}): Booking {
    const slot = backend.freeSlotsNow(STORE_ID)[0];
    return backend.createWalkIn(STORE_ID, {
      supplierId: null,
      externalSupplierName: 'ФОП Гуменюк В. П.',
      plateNumber: 'aa1234bb',
      weightTons: 5,
      palletsCount: 12,
      orderId: null,
      rampId: slot.rampId,
      slotStart: slot.slotStart,
      ...overrides,
    });
  }

  it('генерує реалістичний день з рампами і бронюваннями', () => {
    const config = backend.getStoreConfig(STORE_ID);
    expect(config.ramps.length).toBeGreaterThanOrEqual(3);
    expect(config.maxVehicleWeightTons).toBe(10);

    const board = backend.getBoard(STORE_ID, TODAY);
    expect(board.bookings.length).toBeGreaterThan(5);
    expect(board.now).toBe(NOW);

    const statuses = new Set(board.bookings.map((b) => b.status));
    expect(statuses.has('completed')).toBe(true);
    expect(statuses.has('unloading')).toBe(true);
    expect(board.bookings.some((b) => b.type === 'walk_in')).toBe(true);
  });

  it('проводить walk-in через arrived → unloading → completed (AC-9.3, AC-9.11)', () => {
    const created = walkIn();
    expect(created.type).toBe('walk_in');
    expect(created.status).toBe('arrived');
    expect(created.arrivedAt).toBe(NOW);
    expect(created.vehicle.plateNumber).toBe('AA1234BB');

    const unloading = backend.startUnloading(created.id, created.version);
    expect(unloading.status).toBe('unloading');
    expect(unloading.unloadingStartedAt).toBe(NOW);
    expect(unloading.version).toBe(created.version + 1);

    const completed = backend.completeUnloading(
      unloading.id,
      unloading.version,
      {
        unloadedPalletsCount: 9,
        partialUnload: false,
        partialUnloadReason: 'order_mismatch',
        partialUnloadComment: null,
      },
    );
    expect(completed.status).toBe('completed');
    expect(completed.unloadedPalletsCount).toBe(9);
    // STW-36: недовантаження вмикає partialUnload автоматично.
    expect(completed.partialUnload?.flag).toBe(true);
    expect(completed.partialUnload?.reason).toBe('order_mismatch');

    const log = backend.getAuditLog(completed.id);
    expect(log.map((e) => e.action)).toEqual(
      expect.arrayContaining([
        'created',
        'status_changed',
        'unload_recorded',
      ]),
    );
    expect(log.every((e) => e.at.length > 0)).toBe(true);
  });

  it('повертає 409 на застарілу версію — гонка двох операторів (STW-17, AC-9.9)', () => {
    const created = walkIn();
    const first = backend.startUnloading(created.id, created.version);
    expect(first.status).toBe('unloading');

    expectProblem(
      () => backend.startUnloading(created.id, created.version),
      'BOOKING_STATUS_CONFLICT',
      409,
    );
  });

  it('відхиляє недопустимий статусний перехід', () => {
    const created = walkIn();
    expectProblem(
      () =>
        backend.completeUnloading(created.id, created.version, {
          unloadedPalletsCount: 12,
          partialUnload: false,
          partialUnloadReason: null,
          partialUnloadComment: null,
        }),
      'BOOKING_STATUS_CONFLICT',
      409,
    );
  });

  it('не дає позначити «Не приїхав» до кінця слоту (STW-15)', () => {
    const board = backend.getBoard(STORE_ID, TODAY);
    const future = board.bookings.find(
      (b) => b.status === 'booked' && new Date(b.slotEnd).getTime() > Date.parse(NOW),
    );
    expect(future).toBeDefined();
    expectProblem(
      () => backend.markNoShow(future!.id, future!.version),
      'NO_SHOW_TOO_EARLY',
      422,
    );
  });

  it('фіксує відмову в прийомі з причиною з довідника (STW-35, AC-9.12)', () => {
    const created = walkIn();
    expectProblem(
      () =>
        backend.reject(created.id, created.version, {
          reason: 'other',
          comment: '  ',
        }),
      'REJECT_REASON_REQUIRED',
      422,
    );

    const rejected = backend.reject(created.id, created.version, {
      reason: 'missing_documents',
      comment: null,
    });
    expect(rejected.status).toBe('rejected');
    expect(rejected.rejectedAt?.reason).toBe('missing_documents');
    expect(rejected.rejectedAt?.at).toBe(NOW);
    expect(backend.getAuditLog(rejected.id).some((e) => e.action === 'rejected')).toBe(
      true,
    );
  });

  it('ставить прапорець delayed без зміни статусу і валідує ETA (STW-20, AC-9.5)', () => {
    const created = walkIn();
    expectProblem(
      () =>
        backend.setDelay(created.id, created.version, {
          reason: 'ramp_busy',
          comment: null,
          eta: new Date(Date.parse(created.slotStart) - 60_000).toISOString(),
        }),
      'ETA_BEFORE_SLOT_START',
      422,
    );

    const eta = new Date(Date.parse(created.slotStart) + 45 * 60_000).toISOString();
    const delayed = backend.setDelay(created.id, created.version, {
      reason: 'ramp_busy',
      comment: 'Рампа зайнята',
      eta,
    });
    expect(delayed.status).toBe('arrived');
    expect(delayed.delayed.flag).toBe(true);
    expect(delayed.delayed.eta).toBe(eta);

    const cleared = backend.clearDelay(delayed.id, delayed.version);
    expect(cleared.delayed.flag).toBe(false);
    expect(
      backend.getAuditLog(cleared.id).some((e) => e.action === 'delay_cleared'),
    ).toBe(true);
  });

  it('переводить бронювання на вільну рампу того самого слота (STW-41, AC-9.13)', () => {
    // Слот, у якому вільні щонайменше дві рампи.
    const byStart = new Map<string, string[]>();
    for (const slot of backend.freeSlotsNow(STORE_ID)) {
      byStart.set(slot.slotStart, [
        ...(byStart.get(slot.slotStart) ?? []),
        slot.rampId,
      ]);
    }
    const entry = [...byStart.entries()].find(([, ramps]) => ramps.length >= 2);
    expect(entry).toBeDefined();

    const created = walkIn({
      slotStart: entry![0],
      rampId: entry![1][0],
    });
    const free = backend.freeRampsForSlot(created);
    expect(free.length).toBeGreaterThan(0);

    const moved = backend.reassignRamp(created.id, created.version, {
      rampId: free[0],
    });
    expect(moved.rampId).toBe(free[0]);
    expect(moved.slotStart).toBe(created.slotStart);
    expect(moved.slotEnd).toBe(created.slotEnd);
    expect(
      backend.getAuditLog(moved.id).some((e) => e.action === 'ramp_reassigned'),
    ).toBe(true);

    expectProblem(
      () =>
        backend.reassignRamp(moved.id, moved.version, { rampId: moved.rampId }),
      'RAMP_SLOT_TAKEN',
      422,
    );
  });

  it('перевіряє тоннаж walk-in проти ліміту магазину (STW-38)', () => {
    expectProblem(() => walkIn({ weightTons: 14 }), 'VEHICLE_TOO_HEAVY', 422);
    expectProblem(() => walkIn({ palletsCount: 99 }), 'VALIDATION_FAILED', 422);
    expectProblem(
      () => walkIn({ supplierId: null, externalSupplierName: '   ' }),
      'VALIDATION_FAILED',
      422,
    );
  });

  it('не дає створити walk-in на зайнятий слот (розділ 9.11)', () => {
    const created = walkIn();
    expectProblem(
      () => walkIn({ rampId: created.rampId, slotStart: created.slotStart }),
      'SLOT_ALREADY_BOOKED',
      409,
    );
  });

  it('walk-in враховується у денній статистиці окремим показником (STW-24)', () => {
    const before = computeDailyStats(backend.getBoard(STORE_ID, TODAY).bookings);
    walkIn();
    const after = computeDailyStats(backend.getBoard(STORE_ID, TODAY).bookings);
    expect(after.walkIn).toBe(before.walkIn + 1);
    expect(after.total).toBe(before.total + 1);
  });

  it('забороняє доступ до чужого магазину (STW-02)', () => {
    expectProblem(
      () => backend.getStoreConfig('чужий-магазин'),
      'STORE_FORBIDDEN',
      403,
    );
  });

  it('будує тиждень із 7 днів і станів слотів (STW-25)', () => {
    const week = backend.getWeek(STORE_ID, '2026-08-24');
    expect(week).toHaveLength(7);
    expect(week[0].dateKey).toBe('2026-08-24');
    const states = new Set(week[3].slots.map((s) => s.state));
    expect(states.size).toBeGreaterThan(1);
    expect([...states].every((s) => ['available', 'booked', 'past'].includes(s))).toBe(
      true,
    );
  });
});
