import { MockBackend } from './mock-backend.service';
import { MOCK_USERS } from '../fixtures/mock-data';
import { toBooking } from '../api/wire.mapper';
import { WireBooking, WireWalkInRequest } from '../api/wire.model';
import { AppError } from '../models/problem.model';
import { computeDailyStats } from '../util/board.util';
import { toKyivDateKey } from '../util/date.util';

/** Четвер, 13:00 за Києвом — усередині вікна прийому 08:00–20:00. */
const NOW = '2026-08-27T10:00:00.000Z';
const STORE_ID = MOCK_USERS[0].scope.storeIds[0];
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

  it('видає плоску відповідь із токенами і профілем, як AuthController', () => {
    const result = backend.login({
      email: 'operator@silpo.ua',
      password: 'demo',
    });
    expect(result.tokenType).toBe('Bearer');
    expect(result.accessToken).toContain('mock-access.');
    expect(result.expiresIn).toBeGreaterThan(0);
    expect(result.user.role).toBe('store_operator');
    expect(result.user.scope.storeIds.length).toBeGreaterThan(1);
    expect(result.user.scope.networkWide).toBe(false);
  });

  it('менеджер має власний скоуп, мережева роль — порожній (STW-01/02)', () => {
    expect(
      backend.login({ email: 'manager@silpo.ua', password: 'x' }).user.role,
    ).toBe('store_manager');
    const outsider = backend.login({ email: 'admin@silpo.ua', password: 'x' }).user;
    expect(outsider.role).toBe('network_manager');
    expect(outsider.scope.storeIds).toEqual([]);
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

  function walkIn(overrides: Partial<WireWalkInRequest> = {}): WireBooking {
    const slot = backend.freeSlotsNow(STORE_ID)[0];
    return backend.createWalkIn({
      storeId: STORE_ID,
      rampId: slot.rampId,
      slotStart: slot.slotStart,
      vehicle: { plateNumber: 'aa1234bb', weightTons: 5, brand: null },
      palletsCount: 12,
      supplierId: null,
      supplierName: 'ФОП Гуменюк В. П.',
      orderId: null,
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

  it('віддає бронювання у формі BookingPresenter', () => {
    const booking = backend.getBoard(STORE_ID, TODAY).bookings[0];
    expect(Object.keys(booking)).toEqual(
      expect.arrayContaining([
        'id',
        'type',
        'status',
        'storeId',
        'store',
        'rampId',
        'slotStart',
        'slotEnd',
        'localDate',
        'localTime',
        'supplierId',
        'supplierName',
        'vehicle',
        'driverId',
        'palletsCount',
        'delayed',
        'statusHistory',
      ]),
    );
    // Оптимістичної версії бекенд не має — її не має бути й у моці.
    expect('version' in booking).toBe(false);
    expect(booking.store.displayName).toContain('Сільпо');
  });

  it('проводить walk-in через arrived → unloading → completed (ST-02, ST-03)', () => {
    const created = walkIn();
    expect(created.type).toBe('walk_in');
    expect(created.status).toBe('arrived');
    expect(created.arrivedAt).toBe(NOW);
    expect(created.vehicle.plateNumber).toBe('AA1234BB');

    const unloading = backend.startUnloading(created.id);
    expect(unloading.status).toBe('unloading');
    expect(unloading.unloadingStartedAt).toBe(NOW);

    const completed = backend.completeUnloading(unloading.id, {
      unloadedPalletsCount: 9,
      partialUnload: {
        reason: 'розбіжність із замовленням',
        comment: null,
      },
    });
    expect(completed.status).toBe('completed');
    expect(completed.unloadedPalletsCount).toBe(9);
    expect(completed.partialUnload?.flag).toBe(true);
    expect(completed.partialUnload?.reason).toBe('розбіжність із замовленням');

    // Журнал дій будується зі statusHistory — окремого ендпоінта немає.
    const history = toBooking(completed).statusHistory;
    expect(history.map((e) => e.to)).toEqual([
      'arrived',
      'unloading',
      'completed',
    ]);
    expect(history.every((e) => e.at.length > 0)).toBe(true);
  });

  it('недовантаження без причини — 422 VALIDATION_FAILED (ST-03)', () => {
    const created = walkIn();
    backend.startUnloading(created.id);
    expectProblem(
      () =>
        backend.completeUnloading(created.id, { unloadedPalletsCount: 9 }),
      'VALIDATION_FAILED',
      422,
    );
  });

  it('повторний перехід — 409 INVALID_STATUS_TRANSITION (ST-06)', () => {
    const created = walkIn();
    expect(backend.startUnloading(created.id).status).toBe('unloading');

    expectProblem(
      () => backend.startUnloading(created.id),
      'INVALID_STATUS_TRANSITION',
      409,
    );
  });

  it('відхиляє недопустимий статусний перехід', () => {
    const created = walkIn();
    expectProblem(
      () =>
        backend.completeUnloading(created.id, { unloadedPalletsCount: 12 }),
      'INVALID_STATUS_TRANSITION',
      409,
    );
  });

  it('не дає позначити «Не приїхав» до кінця слоту (NOSH-02)', () => {
    const board = backend.getBoard(STORE_ID, TODAY);
    const future = board.bookings.find(
      (b) => b.status === 'booked' && new Date(b.slotEnd).getTime() > Date.parse(NOW),
    );
    expect(future).toBeDefined();
    expectProblem(
      () => backend.markNoShow(future!.id),
      'VALIDATION_FAILED',
      422,
    );
  });

  it('фіксує відмову з причиною з довідника бекенду (ST-07)', () => {
    const created = walkIn();
    // Значення поза backed-enum RejectionReason → 422.
    expectProblem(
      () => backend.reject(created.id, { reason: 'other', comment: 'x' }),
      'VALIDATION_FAILED',
      422,
    );
    expectProblem(
      () => backend.reject(created.id, { reason: 'інше', comment: '  ' }),
      'VALIDATION_FAILED',
      422,
    );

    const rejected = backend.reject(created.id, {
      reason: 'відсутні документи',
      comment: null,
    });
    expect(rejected.status).toBe('rejected');
    expect(rejected.rejectedAt?.reason).toBe('відсутні документи');
    expect(rejected.rejectedAt?.at).toBe(NOW);
  });

  it('ставить прапорець delayed без зміни статусу і валідує ETA (DLY-01)', () => {
    const created = walkIn();
    expectProblem(
      () =>
        backend.setDelay(created.id, {
          reason: 'затори',
          eta: new Date(Date.parse(NOW) - 60_000).toISOString(),
          comment: null,
        }),
      'VALIDATION_FAILED',
      422,
    );

    const eta = new Date(Date.parse(NOW) + 45 * 60_000).toISOString();
    const delayed = backend.setDelay(created.id, {
      reason: 'затори',
      eta,
      comment: 'Рампа зайнята',
    });
    expect(delayed.status).toBe('arrived');
    expect(delayed.delayed.flag).toBe(true);
    expect(delayed.delayed.eta).toBe(eta);
    expect(delayed.delayed.reason).toBe('затори');

    // ST-02 знімає прапорець затримки — так само, як у Booking::startUnloading.
    expect(backend.startUnloading(delayed.id).delayed.flag).toBe(false);
  });

  it('для причини «інше» склеює коментар у reason, як бекенд', () => {
    const created = walkIn();
    const delayed = backend.setDelay(created.id, {
      reason: 'інше',
      eta: new Date(Date.parse(NOW) + 45 * 60_000).toISOString(),
      comment: 'аварія на мосту',
    });
    expect(delayed.delayed.reason).toBe('інше: аварія на мосту');
  });

  it('переводить бронювання на вільну рампу того самого слота (EDIT-06)', () => {
    const byStart = new Map<string, string[]>();
    for (const slot of backend.freeSlotsNow(STORE_ID)) {
      byStart.set(slot.slotStart, [
        ...(byStart.get(slot.slotStart) ?? []),
        slot.rampId,
      ]);
    }
    const entry = [...byStart.entries()].find(([, ramps]) => ramps.length >= 2);
    expect(entry).toBeDefined();

    const created = walkIn({ slotStart: entry![0], rampId: entry![1][0] });
    const free = backend.freeRampsForSlot(created);
    expect(free.length).toBeGreaterThan(0);

    const moved = backend.reassignRamp(created.id, { rampId: free[0] });
    expect(moved.rampId).toBe(free[0]);
    expect(moved.slotStart).toBe(created.slotStart);
    expect(moved.slotEnd).toBe(created.slotEnd);

    expectProblem(
      () => backend.reassignRamp(moved.id, { rampId: moved.rampId }),
      'SLOT_ALREADY_BOOKED',
      409,
    );
  });

  it('перевіряє тоннаж і палети walk-in проти правил бекенду (WALK-01)', () => {
    expectProblem(
      () => walkIn({ vehicle: { plateNumber: 'AA1', weightTons: 14, brand: null } }),
      'VEHICLE_TOO_HEAVY',
      422,
    );
    expectProblem(() => walkIn({ palletsCount: 99 }), 'PALLETS_OUT_OF_RANGE', 422);
    expectProblem(
      () => walkIn({ supplierId: null, supplierName: '   ' }),
      'VALIDATION_FAILED',
      422,
    );
  });

  it('не дає створити walk-in на зайнятий слот', () => {
    const created = walkIn();
    expectProblem(
      () => walkIn({ rampId: created.rampId, slotStart: created.slotStart }),
      'SLOT_ALREADY_BOOKED',
      409,
    );
  });

  it('walk-in враховується у денній статистиці окремим показником (STW-24)', () => {
    const before = computeDailyStats(
      backend.getBoard(STORE_ID, TODAY).bookings.map(toBooking),
    );
    walkIn();
    const after = computeDailyStats(
      backend.getBoard(STORE_ID, TODAY).bookings.map(toBooking),
    );
    expect(after.walkIn).toBe(before.walkIn + 1);
    expect(after.total).toBe(before.total + 1);
  });

  it('забороняє доступ до чужого магазину (RBAC-13)', () => {
    expectProblem(
      () => backend.getStoreConfig('чужий-магазин'),
      'ACCESS_DENIED',
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
