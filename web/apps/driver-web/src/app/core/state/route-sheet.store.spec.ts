import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import {
  RouteSheetStore,
  activePointIndex,
  isClosedPoint,
} from './route-sheet.store';
import { DriverApi } from '../data/driver.api';
import { NetworkService } from '../offline/network.service';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';
import type { DayRouteSheet, RoutePoint } from '../models/route-sheet.model';

function point(over: Partial<RoutePoint> = {}): RoutePoint {
  return {
    bookingId: 'bk-1',
    city: 'Київ',
    storeName: 'Сільпо №1998',
    address: 'просп. Володимира Івасюка, 46',
    localTime: '09:00',
    slotStart: '2026-08-27T06:00:00Z',
    rampId: 'ramp-2',
    orderId: null,
    palletsCount: 8,
    plateNumber: 'AA 4721 OB',
    driverId: 'drv-1',
    status: 'booked',
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

class FakeDriverApi extends DriverApi {
  /** Ключ — дата, значення — денний зріз або null (порожній день). */
  byDate = new Map<string, DayRouteSheet | null>([
    [kyivDateKey(), sheet([point()])],
  ]);
  sheetError: unknown = null;
  readonly requestedDates: string[] = [];

  override routeSheet(date: string): Observable<DayRouteSheet | null> {
    this.requestedDates.push(date);
    return this.sheetError
      ? throwError(() => this.sheetError)
      : of(this.byDate.get(date) ?? null);
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
