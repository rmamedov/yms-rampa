import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import {
  RouteSheetStore,
  activePointIndex,
  isClosedPoint,
  validateDelay,
} from './route-sheet.store';
import { DriverApi } from '../data/driver.api';
import { NetworkService } from '../offline/network.service';
import { ArrivalQueueService } from '../offline/arrival-queue.service';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';
import {
  NO_DELAY,
  rampLabel,
  type DayRouteSheet,
  type RoutePoint,
} from '../models/route-sheet.model';
import type {
  BookingActionResult,
  DelayReport,
} from '../models/booking-action.model';

function point(over: Partial<RoutePoint> = {}): RoutePoint {
  return {
    bookingId: 'bk-1',
    city: 'Київ',
    storeName: 'Сільпо №1998',
    address: 'просп. Володимира Івасюка, 46',
    latitude: 50.5202,
    longitude: 30.51452,
    localTime: '09:00',
    slotStart: '2026-08-27T06:00:00Z',
    rampId: 'ramp-2',
    rampNumber: 2,
    rampName: 'Рампа 2',
    orderId: null,
    palletsCount: 8,
    plateNumber: 'AA 4721 OB',
    driverId: 'drv-1',
    status: 'booked',
    delayed: NO_DELAY,
    arrivedAt: null,
    ...over,
  };
}

function sheet(points: RoutePoint[], date = kyivDateKey()): DayRouteSheet {
  return {
    date,
    driverId: 'drv-1',
    routeSheetIds: [`rs-${date}`],
    points,
  };
}

function actionResult(
  over: Partial<BookingActionResult> = {},
): BookingActionResult {
  return {
    bookingId: 'bk-1',
    status: 'arrived',
    orderId: null,
    arrivedAt: '2026-08-27T09:12:00Z',
    delayed: { flag: false, reason: null, eta: null },
    ...over,
  };
}

class FakeDriverApi extends DriverApi {
  /** Ключ — дата, значення — денний зріз або null (порожній день). */
  byDate = new Map<string, DayRouteSheet | null>([
    [kyivDateKey(), sheet([point()])],
  ]);
  sheetError: unknown = null;
  readonly requestedDates: string[] = [];

  /** Журнал викликів дій — за ним перевіряється офлайн-черга. */
  readonly arrivedCalls: { bookingId: string; occurredAt: string }[] = [];
  readonly delayCalls: { bookingId: string; report: DelayReport }[] = [];
  readonly orderCalls: { bookingId: string; orderId: string | null }[] = [];

  arrivedError: unknown = null;
  delayError: unknown = null;
  orderError: unknown = null;
  arrivedResult: BookingActionResult = actionResult();

  override routeSheet(date: string): Observable<DayRouteSheet | null> {
    this.requestedDates.push(date);
    return this.sheetError
      ? throwError(() => this.sheetError)
      : of(this.byDate.get(date) ?? null);
  }

  override markArrived(
    bookingId: string,
    occurredAt: string,
  ): Observable<BookingActionResult> {
    this.arrivedCalls.push({ bookingId, occurredAt });
    return this.arrivedError
      ? throwError(() => this.arrivedError)
      : of({ ...this.arrivedResult, bookingId });
  }

  override reportDelay(
    bookingId: string,
    report: DelayReport,
  ): Observable<BookingActionResult> {
    this.delayCalls.push({ bookingId, report });
    return this.delayError
      ? throwError(() => this.delayError)
      : of(
          actionResult({
            bookingId,
            status: 'booked',
            delayed: { flag: true, reason: report.reason, eta: report.eta },
          }),
        );
  }

  override updateOrderId(
    bookingId: string,
    orderId: string | null,
  ): Observable<BookingActionResult> {
    this.orderCalls.push({ bookingId, orderId });
    return this.orderError
      ? throwError(() => this.orderError)
      : of(actionResult({ bookingId, status: 'booked', orderId }));
  }
}

describe('чисті правила проєкції статусів (8.7, DRV-16)', () => {
  it('активна точка — перша незавершена', () => {
    const points = [
      point({ bookingId: 'a', status: 'completed' }),
      point({ bookingId: 'b', status: 'cancelled' }),
      point({ bookingId: 'c', status: 'booked' }),
      point({ bookingId: 'd', status: 'booked' }),
    ];
    expect(activePointIndex(points)).toBe(2);
  });

  it('якщо всі точки закриті — активної немає', () => {
    expect(
      activePointIndex([
        point({ status: 'completed' }),
        point({ status: 'no_show' }),
      ]),
    ).toBe(-1);
  });

  it('закритими вважаються completed, cancelled, no_show і rejected', () => {
    expect(isClosedPoint(point({ status: 'completed' }))).toBe(true);
    expect(isClosedPoint(point({ status: 'rejected' }))).toBe(true);
    expect(isClosedPoint(point({ status: 'booked' }))).toBe(false);
  });
});

/**
 * Дефект UI-тестування: на картці стояло «РАМПА ramp-2» замість «Рампа 2».
 * Водій шукає на дворі ворота з номером, а не службовий ідентифікатор.
 */
describe('підпис рампи (DRV-21)', () => {
  it('номер із довідника перемагає ідентифікатор', () => {
    expect(rampLabel(point({ rampId: 'ramp-2', rampNumber: 2 }))).toBe('2');
  });

  it('без номера показується назва рампи', () => {
    expect(
      rampLabel(
        point({ rampId: 'ramp-3', rampNumber: null, rampName: 'Холодильна' }),
      ),
    ).toBe('Холодильна');
  });

  it('ідентифікатор лишається лише коли довідник філії недоступний', () => {
    expect(
      rampLabel(point({ rampId: 'ramp-2', rampNumber: null, rampName: null })),
    ).toBe('ramp-2');
  });
});

describe('RouteSheetStore', () => {
  let store: RouteSheetStore;
  let api: FakeDriverApi;
  let network: NetworkService;
  let storage: LocalStorageService;

  beforeEach(() => {
    localStorage.clear();
    api = new FakeDriverApi();
    TestBed.configureTestingModule({
      providers: [{ provide: DriverApi, useValue: api }],
    });
    store = TestBed.inject(RouteSheetStore);
    network = TestBed.inject(NetworkService);
    storage = TestBed.inject(LocalStorageService);
    network.setOnline(true);
  });

  it('завантажує лист на сьогодні і кешує його для офлайну (DRV-12, DRV-33)', async () => {
    await store.initialize();

    expect(store.points()).toHaveLength(1);
    expect(store.selectedDate()).toBe(kyivDateKey());
    expect(storage.getRaw(STORAGE_KEYS.routeSheetCache)).toContain('bk-1');
  });

  it('лист запитується завжди з явною датою — бекенд без date бере свою', async () => {
    await store.load(kyivDateKey());

    expect(api.requestedDates).toEqual([kyivDateKey()]);
  });

  it('перелік дат збирається з GET /route-sheet по горизонту (DRV-13)', async () => {
    const today = kyivDateKey();
    const dayAfter = addDaysToDateKey(today, 2);
    api.byDate.set(dayAfter, sheet([point({ bookingId: 'bk-9' })], dayAfter));

    await store.initialize();

    // Опитано сьогодні + 2 дні; порожня дата у чипси не потрапляє.
    expect(api.requestedDates.slice(0, 3)).toEqual([
      today,
      addDaysToDateKey(today, 1),
      dayAfter,
    ]);
    expect(store.dates()).toEqual([
      { date: today, pointCount: 1 },
      { date: dayAfter, pointCount: 1 },
    ]);
  });

  it('без мережі показує кешований лист і піднімає прапорець stale (DRV-33)', async () => {
    await store.initialize();
    api.sheetError = new ApiProblemError(0, { code: 'NETWORK_UNAVAILABLE' });

    await store.load(kyivDateKey());

    expect(store.stale()).toBe(true);
    expect(store.points()).toHaveLength(1);
    expect(store.error()).toBeNull();
    expect(network.online()).toBe(false);
  });

  it('порожній день (routeSheets: []) — це не помилка, а порожній список', async () => {
    const tomorrow = addDaysToDateKey(kyivDateKey(), 1);
    await store.initialize();

    await store.selectDate(tomorrow);

    expect(store.sheet()).toBeNull();
    expect(store.points()).toEqual([]);
    expect(store.error()).toBeNull();
  });

  it('сума палет не враховує скасовані, no_show і rejected точки', async () => {
    api.byDate.set(
      kyivDateKey(),
      sheet([
        point({ bookingId: 'a', palletsCount: 5 }),
        point({ bookingId: 'b', palletsCount: 7, status: 'cancelled' }),
        point({ bookingId: 'c', palletsCount: 4, status: 'rejected' }),
        point({ bookingId: 'd', palletsCount: 3, status: 'completed' }),
      ]),
    );
    await store.initialize();

    expect(store.totalPallets()).toBe(8);
  });

  it('reset очищає стан при виході (DRV-09)', async () => {
    await store.initialize();

    store.reset();

    expect(store.sheet()).toBeNull();
    expect(store.dates()).toEqual([]);
  });
});

describe('RouteSheetStore — дії водія', () => {
  let store: RouteSheetStore;
  let api: FakeDriverApi;
  let network: NetworkService;
  let queue: ArrivalQueueService;

  beforeEach(async () => {
    localStorage.clear();
    api = new FakeDriverApi();
    TestBed.configureTestingModule({
      providers: [{ provide: DriverApi, useValue: api }],
    });
    store = TestBed.inject(RouteSheetStore);
    network = TestBed.inject(NetworkService);
    queue = TestBed.inject(ArrivalQueueService);
    network.setOnline(true);
    await store.initialize();
  });

  // --- «На місці» -------------------------------------------------------------

  it('успішна відмітка переводить точку в arrived і не лишає нічого в черзі', async () => {
    await store.markArrived('bk-1');

    expect(api.arrivedCalls).toHaveLength(1);
    expect(store.points()[0].status).toBe('arrived');
    expect(store.queuedArrivals()).toBe(0);
    expect(store.actionError()).toBeNull();
  });

  it('ідемпотентна відповідь — це успіх, а не збій', async () => {
    // Магазин відмітив прибуття першим: бекенд віддає 200 і поточний стан.
    api.arrivedResult = actionResult({ arrivedAt: '2026-08-27T08:55:00Z' });

    await store.markArrived('bk-1');

    expect(store.actionError()).toBeNull();
    expect(store.points()[0].status).toBe('arrived');
  });

  it('403 на чужому бронюванні показується зрозумілим текстом', async () => {
    api.arrivedError = new ApiProblemError(403, { code: 'ACCESS_DENIED' });

    await store.markArrived('bk-1');

    expect(store.actionError()).toBe(
      'Ця точка не входить до вашого маршрутного листа',
    );
    expect(store.points()[0].status).toBe('booked');
  });

  // --- Офлайн-черга -----------------------------------------------------------

  it('без мережі відмітка стає в чергу і НЕ йде на сервер', async () => {
    network.setOnline(false);

    await store.markArrived('bk-1');

    expect(api.arrivedCalls).toHaveLength(0);
    expect(store.queuedArrivals()).toBe(1);
    expect(store.isQueued('bk-1')).toBe(true);
    expect(store.actionError()).toBeNull();
  });

  it('мережевий збій під час відправки теж кладе відмітку в чергу', async () => {
    api.arrivedError = new ApiProblemError(0, { code: 'NETWORK_UNAVAILABLE' });

    await store.markArrived('bk-1');

    expect(store.queuedArrivals()).toBe(1);
    expect(store.actionError()).toBeNull();
  });

  it('черга відправляється з ФАКТИЧНИМ часом натискання, а не часом відправки', async () => {
    network.setOnline(false);
    await store.markArrived('bk-1');
    const tapped = queue.occurredAt('bk-1');

    network.setOnline(true);
    await store.flushArrivalQueue();

    expect(api.arrivedCalls).toEqual([
      { bookingId: 'bk-1', occurredAt: tapped },
    ]);
    expect(store.queuedArrivals()).toBe(0);
    expect(store.points()[0].status).toBe('arrived');
  });

  it('якщо прибуття вже відмічене, черга очищається тихо — без помилки', async () => {
    network.setOnline(false);
    await store.markArrived('bk-1');
    network.setOnline(true);
    // Точка вже поїхала далі: магазин почав розвантаження (409 ST-06).
    api.arrivedError = new ApiProblemError(409, {
      code: 'INVALID_STATUS_TRANSITION',
    });

    await store.flushArrivalQueue();

    expect(store.queuedArrivals()).toBe(0);
    expect(store.actionError()).toBeNull();
  });

  it('без звʼязку черга зберігається до наступної спроби', async () => {
    network.setOnline(false);
    await store.markArrived('bk-1');
    network.setOnline(true);
    api.arrivedError = new ApiProblemError(0, { code: 'NETWORK_UNAVAILABLE' });

    await store.flushArrivalQueue();

    expect(store.queuedArrivals()).toBe(1);
    expect(network.online()).toBe(false);
  });

  it('успішне завантаження листа саме віддає накопичену чергу', async () => {
    network.setOnline(false);
    await store.markArrived('bk-1');
    network.setOnline(true);
    api.arrivedCalls.length = 0;

    await store.load(kyivDateKey());

    expect(api.arrivedCalls).toHaveLength(1);
    expect(store.queuedArrivals()).toBe(0);
  });

  it('вихід із застосунку не лишає чужих відміток у черзі (DRV-09)', async () => {
    network.setOnline(false);
    await store.markArrived('bk-1');

    store.reset();

    expect(store.queuedArrivals()).toBe(0);
  });

  // --- Затримка ---------------------------------------------------------------

  it('затримку з причиною довідника передає на сервер і показує на картці', async () => {
    const eta = new Date(Date.now() + 30 * 60_000).toISOString();

    const ok = await store.reportDelay('bk-1', { reason: 'затори', eta });

    expect(ok).toBe(true);
    expect(api.delayCalls[0].report.reason).toBe('затори');
    expect(store.delayOf('bk-1')).toEqual({
      flag: true,
      reason: 'затори',
      eta,
    });
  });

  it('ETA в минулому відсікається до запиту — водій бачить підказку одразу', async () => {
    const ok = await store.reportDelay('bk-1', {
      reason: 'затори',
      eta: new Date(Date.now() - 60_000).toISOString(),
    });

    expect(ok).toBe(false);
    expect(api.delayCalls).toHaveLength(0);
    expect(store.actionError()).toBe(
      'Новий час прибуття має бути в майбутньому',
    );
  });

  it('причина «інше» без коментаря відсікається до запиту', async () => {
    const ok = await store.reportDelay('bk-1', {
      reason: 'інше',
      eta: new Date(Date.now() + 30 * 60_000).toISOString(),
      comment: '   ',
    });

    expect(ok).toBe(false);
    expect(api.delayCalls).toHaveLength(0);
    expect(store.actionError()).toBe(
      'Для причини «Інше» коментар обовʼязковий',
    );
  });

  /**
   * Дефект UI-тестування: банер затримки був відлунням власної дії водія і
   * зникав після перезавантаження сторінки. Тепер `delayed` є у проєкції
   * листа, тож переживає і F5, і полінг.
   */
  it('затримка з листа переживає перезавантаження і полінг', async () => {
    const eta = '2026-08-27T10:30:00Z';
    api.byDate.set(
      kyivDateKey(),
      sheet([
        point({
          delayed: { flag: true, reason: 'затори', eta },
          arrivedAt: '2026-08-27T09:12:00Z',
        }),
      ]),
    );

    // Свіжий застосунок: жодної дії водія в цій сесії не було.
    await store.load(kyivDateKey());

    expect(store.delayOf('bk-1')).toEqual({ flag: true, reason: 'затори', eta });
    expect(store.arrivedAtOf('bk-1')).toBe('2026-08-27T09:12:00Z');
  });

  it('полінг не гасить затримку, яку підтверджує сервер', async () => {
    const eta = new Date(Date.now() + 30 * 60_000).toISOString();
    await store.reportDelay('bk-1', { reason: 'затори', eta });
    // Лист із сервера підтверджує затримку — стан не має «моргнути».
    api.byDate.set(
      kyivDateKey(),
      sheet([point({ delayed: { flag: true, reason: 'затори', eta } })]),
    );

    await store.load(kyivDateKey(), { silent: true });

    expect(store.delayOf('bk-1')?.flag).toBe(true);
  });

  it('знятий магазином прапорець гасить банер (ST-02)', async () => {
    const eta = new Date(Date.now() + 30 * 60_000).toISOString();
    await store.reportDelay('bk-1', { reason: 'затори', eta });
    // Магазин почав розвантаження — бекенд віддає лист уже без затримки.
    api.byDate.set(
      kyivDateKey(),
      sheet([point({ status: 'unloading', delayed: NO_DELAY })]),
    );

    await store.load(kyivDateKey(), { silent: true });

    expect(store.delayOf('bk-1')).toBeNull();
  });

  it('відповідь на дію одразу кладе затримку і час прибуття в точку листа', async () => {
    const eta = new Date(Date.now() + 30 * 60_000).toISOString();

    await store.markArrived('bk-1');
    await store.reportDelay('bk-1', { reason: 'затори', eta });

    expect(store.points()[0].delayed).toEqual({
      flag: true,
      reason: 'затори',
      eta,
    });
    expect(store.points()[0].arrivedAt).toBe('2026-08-27T09:12:00Z');
  });

  it('422 бекенду показується його ж поясненням', async () => {
    api.delayError = new ApiProblemError(422, {
      code: 'VALIDATION_FAILED',
      detail: 'ETA має бути в майбутньому',
    });

    const ok = await store.reportDelay('bk-1', {
      reason: 'затори',
      eta: new Date(Date.now() + 30 * 60_000).toISOString(),
    });

    expect(ok).toBe(false);
    expect(store.actionError()).toBe('ETA має бути в майбутньому');
  });

  // --- orderId ----------------------------------------------------------------

  it('редагує orderId і оновлює точку листа', async () => {
    const ok = await store.updateOrderId('bk-1', '4410999');

    expect(ok).toBe(true);
    expect(api.orderCalls).toEqual([{ bookingId: 'bk-1', orderId: '4410999' }]);
    expect(store.points()[0].orderId).toBe('4410999');
  });

  it('заборона після початку розвантаження показується поясненням бекенду', async () => {
    api.orderError = new ApiProblemError(422, {
      code: 'VALIDATION_FAILED',
      detail: 'Номер замовлення можна вказати лише до початку розвантаження',
    });

    const ok = await store.updateOrderId('bk-1', '4410999');

    expect(ok).toBe(false);
    expect(store.actionError()).toBe(
      'Номер замовлення можна вказати лише до початку розвантаження',
    );
    expect(store.points()[0].orderId).toBeNull();
  });

  it('без detail від бекенду показується власне пояснення застосунку', async () => {
    api.orderError = new ApiProblemError(422, { code: 'VALIDATION_FAILED' });

    await store.updateOrderId('bk-1', '4410999');

    expect(store.actionError()).toBe(
      'Номер замовлення можна вказати лише до початку розвантаження',
    );
  });
});

describe('validateDelay — дзеркало Booking::setDelay', () => {
  const now = Date.parse('2026-08-27T09:00:00Z');
  const eta = '2026-08-27T09:30:00Z';

  it('ETA в майбутньому з причиною довідника заперечень не має', () => {
    expect(validateDelay({ reason: 'затори', eta }, now)).toBeNull();
  });

  it('ETA рівно «зараз» уже минуле — бекенд вимагає строго майбутнє', () => {
    expect(
      validateDelay({ reason: 'затори', eta: '2026-08-27T09:00:00Z' }, now),
    ).toBe('delay.etaInPast');
  });

  it('«інше» без коментаря — заперечення, з коментарем — ні', () => {
    expect(validateDelay({ reason: 'інше', eta }, now)).toBe(
      'delay.commentRequired',
    );
    expect(
      validateDelay({ reason: 'інше', eta, comment: 'прокол' }, now),
    ).toBeNull();
  });
});
