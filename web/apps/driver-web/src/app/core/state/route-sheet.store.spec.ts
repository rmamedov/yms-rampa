import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import {
  RouteSheetStore,
  activePointIndex,
  canEditOrderId,
  isClosedPoint,
} from './route-sheet.store';
import { DriverApi } from '../data/driver.api';
import { NetworkService } from '../offline/network.service';
import { ArrivalQueueService } from '../offline/arrival-queue.service';
import { GeolocationService } from '../geo/geolocation.service';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import { kyivDateKey } from '../util/time.util';
import type {
  ArrivePayload,
  AvailableDate,
  DelayPayload,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';

const STORE = {
  storeId: 'st-1',
  externalId: '1998',
  name: 'Сільпо №1998',
  city: 'Київ',
  address: 'просп. Володимира Івасюка, 46',
  latitude: 50.5,
  longitude: 30.5,
};

function point(over: Partial<RoutePoint> = {}): RoutePoint {
  return {
    bookingId: 'bk-1',
    slotStart: '2026-08-27T09:00:00.000Z',
    slotEnd: '2026-08-27T10:00:00.000Z',
    store: STORE,
    rampNumber: '2',
    orderId: null,
    pallets: 8,
    status: 'booked',
    delayed: null,
    arrivedAt: null,
    ...over,
  };
}

function sheet(points: RoutePoint[], date = kyivDateKey()): RouteSheet {
  return {
    routeSheetId: `rs-${date}`,
    date,
    driverId: 'drv-1',
    driverName: 'Петренко Іван',
    driverPhone: '+380671234567',
    supplierName: 'ТОВ «Тест»',
    vehicle: { plate: 'AA 4721 OB', model: 'Atego', capacityTons: 8 },
    points,
    updatedAt: new Date().toISOString(),
  };
}

class FakeDriverApi extends DriverApi {
  sheetValue: RouteSheet | null = sheet([point()]);
  sheetError: unknown = null;
  arriveError: unknown = null;
  readonly arriveCalls: ArrivePayload[] = [];

  override availableDates(): Observable<readonly AvailableDate[]> {
    return of([{ date: kyivDateKey(), pointCount: 1 }]);
  }
  override routeSheet(): Observable<RouteSheet | null> {
    return this.sheetError
      ? throwError(() => this.sheetError)
      : of(this.sheetValue);
  }
  override setOrderId(bookingId: string, orderId: string): Observable<RoutePoint> {
    return of(point({ bookingId, orderId }));
  }
  override arrive(bookingId: string, payload: ArrivePayload): Observable<RoutePoint> {
    this.arriveCalls.push(payload);
    if (this.arriveError) {
      return throwError(() => this.arriveError);
    }
    return of(point({ bookingId, status: 'arrived', arrivedAt: payload.pressedAt }));
  }
  override setDelay(bookingId: string, payload: DelayPayload): Observable<RoutePoint> {
    return of(
      point({
        bookingId,
        delayed: { eta: payload.eta, reason: payload.reason, setBy: 'driver' },
      }),
    );
  }
}

describe('чисті правила проєкції статусів (8.7, DRV-16, DRV-19)', () => {
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

  it('редагування orderId дозволене для booked/arrived/unloading', () => {
    expect(canEditOrderId(point({ status: 'booked' }))).toBe(true);
    expect(canEditOrderId(point({ status: 'arrived' }))).toBe(true);
    expect(canEditOrderId(point({ status: 'unloading' }))).toBe(true);
    expect(canEditOrderId(point({ status: 'completed' }))).toBe(false);
    expect(canEditOrderId(point({ status: 'cancelled' }))).toBe(false);
    expect(canEditOrderId(point({ status: 'no_show' }))).toBe(false);
  });

  it('закритими вважаються completed, cancelled, no_show', () => {
    expect(isClosedPoint(point({ status: 'completed' }))).toBe(true);
    expect(isClosedPoint(point({ status: 'booked' }))).toBe(false);
  });
});

describe('RouteSheetStore', () => {
  let store: RouteSheetStore;
  let api: FakeDriverApi;
  let network: NetworkService;
  let queue: ArrivalQueueService;
  let storage: LocalStorageService;

  beforeEach(() => {
    localStorage.clear();
    api = new FakeDriverApi();
    TestBed.configureTestingModule({
      providers: [
        { provide: DriverApi, useValue: api },
        {
          provide: GeolocationService,
          useValue: { current: () => Promise.resolve({ latitude: null, longitude: null }) },
        },
      ],
    });
    store = TestBed.inject(RouteSheetStore);
    network = TestBed.inject(NetworkService);
    queue = TestBed.inject(ArrivalQueueService);
    storage = TestBed.inject(LocalStorageService);
    network.setOnline(true);
  });

  it('завантажує лист на сьогодні і кешує його для офлайну (DRV-12, DRV-33)', async () => {
    await store.initialize();

    expect(store.points()).toHaveLength(1);
    expect(store.selectedDate()).toBe(kyivDateKey());
    expect(storage.getRaw(STORAGE_KEYS.routeSheetCache)).toContain('bk-1');
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

  it('«На місці» онлайн надсилає фактичний час натискання і оновлює точку', async () => {
    await store.initialize();
    const pressedAt = '2026-08-27T09:12:00.000Z';

    const result = await store.markArrived('bk-1', pressedAt);

    expect(result).toEqual({ ok: true, queued: false });
    expect(api.arriveCalls[0].pressedAt).toBe(pressedAt);
    expect(store.points()[0].status).toBe('arrived');
    expect(queue.pendingCount()).toBe(0);
  });

  it('«На місці» офлайн ставить відмітку в чергу з фактичним часом (DRV-34)', async () => {
    await store.initialize();
    network.setOnline(false);
    const pressedAt = '2026-08-27T09:12:00.000Z';

    const result = await store.markArrived('bk-1', pressedAt);

    expect(result).toEqual({ ok: true, queued: true });
    expect(api.arriveCalls).toHaveLength(0);
    expect(queue.queuedFor('bk-1')?.pressedAt).toBe(pressedAt);
  });

  it('мережевий збій під час відправки теж переводить відмітку в чергу', async () => {
    await store.initialize();
    api.arriveError = new ApiProblemError(0, { code: 'NETWORK_UNAVAILABLE' });

    const result = await store.markArrived('bk-1', '2026-08-27T09:12:00.000Z');

    expect(result.queued).toBe(true);
    expect(queue.pendingCount()).toBe(1);
    expect(network.online()).toBe(false);
  });

  it('409 «вже відмічено» показує повідомлення і не створює черги (DRV-28/DRV-29)', async () => {
    await store.initialize();
    api.arriveError = new ApiProblemError(409, {
      code: 'BOOKING_ALREADY_ARRIVED',
      detail: 'Прибуття вже відмічено',
    });

    const result = await store.markArrived('bk-1', '2026-08-27T09:12:00.000Z');

    expect(result.ok).toBe(false);
    expect(result.message).toBe('Прибуття вже відмічено');
    expect(queue.pendingCount()).toBe(0);
  });

  it('збереження orderId оновлює точку в сторі (DRV-17)', async () => {
    await store.initialize();

    const result = await store.saveOrderId('bk-1', '  4410999 ');

    expect(result.ok).toBe(true);
    expect(store.points()[0].orderId).toBe('4410999');
  });

  it('повідомлення про затримку зберігає ETA і причину (DRV-41)', async () => {
    await store.initialize();
    const eta = new Date(Date.now() + 30 * 60_000).toISOString();

    const result = await store.reportDelay('bk-1', { eta, reason: 'Затор' });

    expect(result.ok).toBe(true);
    expect(store.points()[0].delayed).toEqual({
      eta,
      reason: 'Затор',
      setBy: 'driver',
    });
  });

  it('сума палет не враховує скасовані точки', async () => {
    api.sheetValue = sheet([
      point({ bookingId: 'a', pallets: 5 }),
      point({ bookingId: 'b', pallets: 7, status: 'cancelled' }),
      point({ bookingId: 'c', pallets: 3, status: 'completed' }),
    ]);
    await store.initialize();

    expect(store.totalPallets()).toBe(8);
  });

  it('flushQueue відправляє накопичені відмітки після повернення мережі', async () => {
    await store.initialize();
    network.setOnline(false);
    await store.markArrived('bk-1', '2026-08-27T09:12:00.000Z');
    expect(queue.pendingCount()).toBe(1);

    network.setOnline(true);
    await store.flushQueue();

    expect(api.arriveCalls).toHaveLength(1);
    expect(api.arriveCalls[0].pressedAt).toBe('2026-08-27T09:12:00.000Z');
    expect(queue.pendingCount()).toBe(0);
    expect(store.points()[0].status).toBe('arrived');
  });

  it('reset очищає стан і чергу при виході (DRV-09)', async () => {
    await store.initialize();
    network.setOnline(false);
    await store.markArrived('bk-1', '2026-08-27T09:12:00.000Z');

    store.reset();

    expect(store.sheet()).toBeNull();
    expect(store.dates()).toEqual([]);
    expect(queue.pendingCount()).toBe(0);
  });
});
