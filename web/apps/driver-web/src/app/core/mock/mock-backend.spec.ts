import { TestBed } from '@angular/core/testing';
import { MockBackend } from './mock-backend';
import { ApiProblemError } from '../models/problem.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';

/**
 * Мок мусить повторювати РЕАЛЬНИЙ контракт driver_route_sheet, інакше
 * режим розробки знову розійдеться з дійсністю.
 */
describe('MockBackend — дзеркало GET /api/driver/v1/route-sheet', () => {
  let backend: MockBackend;
  const now = Date.now();

  beforeEach(() => {
    TestBed.configureTestingModule({});
    backend = TestBed.inject(MockBackend);
    backend.reset(now);
  });

  it('віддає конверт {driverId, date, routeSheets} як у контролері', () => {
    const date = kyivDateKey(now);
    const response = backend.routeSheet(date, now);

    expect(Object.keys(response).sort()).toEqual(['date', 'driverId', 'routeSheets']);
    expect(response.date).toBe(date);
    expect(response.driverId).toBe('drv-1001');
    expect(response.routeSheets).toHaveLength(1);
  });

  it('лист має поля RouteSheetService::forDriver', () => {
    const sheet = backend.routeSheet(kyivDateKey(now), now).routeSheets[0];

    expect(Object.keys(sheet).sort()).toEqual([
      'date',
      'points',
      'printVersion',
      'routeSheetId',
      'supplierId',
    ]);
  });

  it('точка має рівно поля RouteSheetService::point', () => {
    const point = backend.routeSheet(kyivDateKey(now), now).routeSheets[0].points[0];

    expect(Object.keys(point).sort()).toEqual([
      'address',
      'arrivedAt',
      'bookingId',
      'city',
      'delayed',
      'driverId',
      'latitude',
      'localTime',
      'longitude',
      'orderId',
      'palletsCount',
      'plateNumber',
      'rampId',
      'rampName',
      'rampNumber',
      'slotStart',
      'status',
      'storeName',
    ]);
  });

  it('точка несе координати філії — за ними будується маршрут (DRV-21)', () => {
    const point = backend.routeSheet(kyivDateKey(now), now).routeSheets[0].points[0];

    expect(typeof point.latitude).toBe('number');
    expect(typeof point.longitude).toBe('number');
    // Довідник філій мока — зріз fixtures/silpo-branches.json, координати справжні.
    expect(point.latitude).toBeGreaterThan(44);
    expect(point.latitude).toBeLessThan(52.5);
    expect(point.longitude).toBeGreaterThan(22);
    expect(point.longitude).toBeLessThan(40.5);
  });

  it('точка несе номер і назву рампи, а не лише службовий rampId', () => {
    const points = backend.routeSheet(kyivDateKey(now), now).routeSheets[0].points;
    const point = points.find((p) => p.rampId === 'ramp-2');

    expect(point?.rampNumber).toBe(2);
    expect(point?.rampName).toBe('Рампа 2');
  });

  it('slotStart серіалізується без мілісекунд, як у PHP `Y-m-d\\TH:i:s\\Z`', () => {
    const point = backend.routeSheet(kyivDateKey(now), now).routeSheets[0].points[0];

    expect(point.slotStart).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    expect(point.localTime).toMatch(/^\d{2}:\d{2}$/);
  });

  it('точки листа відсортовані за часом слоту (RSHT-03)', () => {
    const starts = backend
      .routeSheet(kyivDateKey(now), now)
      .routeSheets[0].points.map((p) => p.slotStart);

    expect(starts.length).toBeGreaterThan(1);
    expect([...starts].sort()).toEqual(starts);
  });

  it('порожній день — це 200 і routeSheets: [], а не 404', () => {
    const empty = backend.routeSheet(addDaysToDateKey(kyivDateKey(now), 10), now);

    expect(empty.routeSheets).toEqual([]);
  });

  it('некоректний date відхиляється з 422 VALIDATION_FAILED, як у контролері', () => {
    expect.assertions(2);
    try {
      backend.routeSheet('27.08.2026', now);
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(422);
      expect((error as ApiProblemError).code).toBe('VALIDATION_FAILED');
    }
  });

  it('без параметра date береться поточна київська дата (як у контролері)', () => {
    expect(backend.routeSheet(undefined, now).date).toBe(kyivDateKey());
  });
});

/**
 * Дії водія у моці мусять повторювати BookingActionController +
 * DriverBookingService, інакше режим розробки знову розійдеться з дійсністю.
 */
describe('MockBackend — дії контуру водія', () => {
  let backend: MockBackend;
  const now = Date.now();

  /** Точка потрібного статусу з посіву на сьогодні. */
  const pointWith = (status: string) => {
    const points = backend.routeSheet(kyivDateKey(now), now).routeSheets[0].points;
    const found = points.find((p) => p.status === status);
    if (!found) {
      throw new Error(`у посіві немає точки зі статусом ${status}`);
    }
    return found;
  };

  const inFuture = (minutes: number) =>
    new Date(now + minutes * 60_000).toISOString();

  beforeEach(() => {
    TestBed.configureTestingModule({});
    backend = TestBed.inject(MockBackend);
    backend.reset(now);
  });

  it('«На місці» переводить booked → arrived і штампує arrivedAt', () => {
    const point = pointWith('booked');

    const response = backend.markArrived(point.bookingId, now);

    expect(response.id).toBe(point.bookingId);
    expect(response.status).toBe('arrived');
    expect(response.arrivedAt).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  });

  it('повторна відмітка ІДЕМПОТЕНТНА: поточний стан без помилки', () => {
    const point = pointWith('booked');
    const first = backend.markArrived(point.bookingId, now);

    const second = backend.markArrived(point.bookingId, now + 60_000);

    expect(second.status).toBe('arrived');
    // Другого переходу немає — час прибуття лишається від першої відмітки.
    expect(second.arrivedAt).toBe(first.arrivedAt);
  });

  it('точка, яку магазин уже розвантажує, дає 409 INVALID_STATUS_TRANSITION', () => {
    const point = pointWith('unloading');

    expect.assertions(2);
    try {
      backend.markArrived(point.bookingId, now);
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(409);
      expect((error as ApiProblemError).code).toBe('INVALID_STATUS_TRANSITION');
    }
  });

  it('невідоме бронювання — 404 BOOKING_NOT_FOUND', () => {
    expect.assertions(1);
    try {
      backend.markArrived('bk-невідоме', now);
    } catch (error) {
      expect((error as ApiProblemError).code).toBe('BOOKING_NOT_FOUND');
    }
  });

  it('затримка приймає причину з довідника та ETA в майбутньому', () => {
    const point = pointWith('booked');

    const response = backend.reportDelay(
      point.bookingId,
      'затори',
      inFuture(30),
      null,
      now,
    );

    expect(response.delayed).toEqual({
      flag: true,
      reason: 'затори',
      eta: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/),
    });
    // Затримка не змінює статус бронювання (DLY-01).
    expect(response.status).toBe('booked');
  });

  it('ETA в минулому відхиляється з 422', () => {
    const point = pointWith('booked');

    expect.assertions(3);
    try {
      backend.reportDelay(point.bookingId, 'затори', inFuture(-5), null, now);
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(422);
      expect((error as ApiProblemError).code).toBe('VALIDATION_FAILED');
      expect((error as ApiProblemError).problem.detail).toBe(
        'ETA має бути в майбутньому',
      );
    }
  });

  it('причина поза довідником відхиляється з 422', () => {
    const point = pointWith('booked');

    expect.assertions(1);
    try {
      backend.reportDelay(point.bookingId, 'traffic_jam', inFuture(30), null, now);
    } catch (error) {
      expect((error as ApiProblemError).code).toBe('VALIDATION_FAILED');
    }
  });

  it('причина «інше» без коментаря відхиляється, а з коментарем — склеюється', () => {
    const point = pointWith('booked');

    expect(() =>
      backend.reportDelay(point.bookingId, 'інше', inFuture(30), '  ', now),
    ).toThrow(ApiProblemError);

    const ok = backend.reportDelay(
      point.bookingId,
      'інше',
      inFuture(30),
      'прокол колеса',
      now,
    );

    expect(ok.delayed?.reason).toBe('інше: прокол колеса');
  });

  it('orderId редагується до розвантаження і очищається порожнім рядком', () => {
    const point = pointWith('booked');

    expect(backend.updateOrderId(point.bookingId, ' 4410999 ', now).orderId).toBe(
      '4410999',
    );
    expect(backend.updateOrderId(point.bookingId, '', now).orderId).toBeNull();
  });

  it('orderId після початку розвантаження відхиляється з 422', () => {
    const point = pointWith('unloading');

    expect.assertions(2);
    try {
      backend.updateOrderId(point.bookingId, '4410999', now);
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(422);
      expect((error as ApiProblemError).problem.detail).toBe(
        'Номер замовлення можна вказати лише до початку розвантаження',
      );
    }
  });

  it('дія віддає BookingPresenter (id), а лист — проєкцію точки (bookingId)', () => {
    const point = pointWith('booked');

    const response = backend.markArrived(point.bookingId, now);

    expect(Object.keys(response).sort()).toEqual([
      'arrivedAt',
      'delayed',
      'id',
      'orderId',
      'status',
    ]);
    // Ідентифікатор бронювання називається по-різному — це різні контракти.
    const listed = backend
      .routeSheet(kyivDateKey(now), now)
      .routeSheets[0].points.find((p) => p.bookingId === point.bookingId);
    expect(listed).not.toHaveProperty('id');
    expect(listed?.status).toBe('arrived');
  });

  /**
   * Дефект UI-тестування: банер затримки був відлунням власної дії водія
   * і зникав після перезавантаження. Тепер стан лежить у самій точці листа.
   */
  it('затримка і час прибуття лишаються в листі після повторного читання', () => {
    const point = pointWith('booked');
    backend.reportDelay(point.bookingId, 'затори', inFuture(30), null, now);
    backend.markArrived(point.bookingId, now);

    // Свіже читання листа — рівно те, що зробить полінг після F5.
    const listed = backend
      .routeSheet(kyivDateKey(now), now)
      .routeSheets[0].points.find((p) => p.bookingId === point.bookingId);

    expect(listed?.delayed).toEqual({
      flag: true,
      reason: 'затори',
      eta: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/),
    });
    expect(listed?.arrivedAt).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  });
});
