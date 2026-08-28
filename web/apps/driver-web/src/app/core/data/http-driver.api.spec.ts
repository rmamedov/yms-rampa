import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from './driver.api';
import { HttpDriverApi } from './http-driver.api';
import type { DriverRouteSheetResponse } from '../models/route-sheet.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';

/**
 * Тест закріплює РЕАЛЬНИЙ маршрут бекенду:
 *   GET /api/driver/v1/route-sheet?date=YYYY-MM-DD
 * (booking-service, driver_route_sheet). Множини `/route-sheets` і шляху
 * з датою в сегменті у бекенді не існує.
 */
describe('HttpDriverApi — контракт driver_route_sheet', () => {
  let api: DriverApi;
  let http: HttpTestingController;

  const envelope = (
    date: string,
    points: DriverRouteSheetResponse['routeSheets'][number]['points'],
  ): DriverRouteSheetResponse => ({
    driverId: 'drv-1001',
    date,
    routeSheets:
      points.length > 0
        ? [
            {
              routeSheetId: `rs-${date}`,
              supplierId: 'sup-77',
              date,
              printVersion: 1,
              points,
            },
          ]
        : [],
  });

  const point = (bookingId: string, slotStart: string, localTime: string) => ({
    bookingId,
    city: 'Київ',
    storeName: 'Сільпо №1998',
    address: 'просп. Володимира Івасюка, 46',
    latitude: 50.5202,
    longitude: 30.51452,
    localTime,
    slotStart,
    rampId: 'ramp-2',
    rampNumber: 2,
    rampName: 'Рампа 2',
    orderId: null,
    palletsCount: 8,
    plateNumber: 'AA 4721 OB',
    driverId: 'drv-1001',
    status: 'booked' as const,
    delayed: { flag: false, reason: null, eta: null },
    arrivedAt: null,
  });

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: DriverApi, useClass: HttpDriverApi },
      ],
    });
    api = TestBed.inject(DriverApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('звертається до /api/driver/v1/route-sheet і завжди передає date', async () => {
    const promise = firstValueFrom(api.routeSheet('2026-08-27'));

    const request = http.expectOne(
      (r) => r.url === '/api/driver/v1/route-sheet' && r.method === 'GET',
    );
    expect(request.request.params.get('date')).toBe('2026-08-27');
    request.flush(
      envelope('2026-08-27', [point('bk-1', '2026-08-27T06:00:00Z', '09:00')]),
    );

    const { sheet, fromCache, cachedAt } = await promise;
    expect(sheet?.points[0].bookingId).toBe('bk-1');
    expect(sheet?.routeSheetIds).toEqual(['rs-2026-08-27']);
    // Координати, рампа і стан бронювання доїжджають до застосунку як є.
    expect(sheet?.points[0].latitude).toBe(50.5202);
    expect(sheet?.points[0].longitude).toBe(30.51452);
    expect(sheet?.points[0].rampNumber).toBe(2);
    expect(sheet?.points[0].delayed).toEqual({
      flag: false,
      reason: null,
      eta: null,
    });
    // Відповідь із мережі не має ознак збереженої копії.
    expect(fromCache).toBe(false);
    expect(cachedAt).toBeNull();
  });

  /**
   * ISSUE-10: без звʼязку service worker віддає збережену копію звичайним 200
   * і підписує її заголовками. Застосунок мусить прочитати підпис — інакше
   * офлайн-відповідь не відрізнити від свіжої.
   */
  it('позначає відповідь із кешу service worker як несвіжу', async () => {
    const promise = firstValueFrom(api.routeSheet('2026-08-27'));

    http.expectOne((r) => r.url === '/api/driver/v1/route-sheet').flush(
      envelope('2026-08-27', [point('bk-1', '2026-08-27T06:00:00Z', '09:00')]),
      {
        headers: {
          'x-yms-from-cache': '1',
          'x-yms-cached-at': '2026-08-27T05:40:00.000Z',
        },
      },
    );

    const load = await promise;
    expect(load.sheet?.points[0].bookingId).toBe('bk-1');
    expect(load.fromCache).toBe(true);
    expect(load.cachedAt).toBe(Date.parse('2026-08-27T05:40:00.000Z'));
  });

  it('порожній день бекенд віддає як 200 з routeSheets: [] → null', async () => {
    const promise = firstValueFrom(api.routeSheet('2026-08-28'));

    http
      .expectOne((r) => r.url === '/api/driver/v1/route-sheet')
      .flush(envelope('2026-08-28', []));

    expect((await promise).sheet).toBeNull();
  });

  it('кілька листів дати склеюються в один маршрут за часом слоту', async () => {
    const promise = firstValueFrom(api.routeSheet('2026-08-27'));

    http.expectOne((r) => r.url === '/api/driver/v1/route-sheet').flush({
      driverId: 'drv-1001',
      date: '2026-08-27',
      routeSheets: [
        {
          routeSheetId: 'rs-b',
          supplierId: 'sup-2',
          date: '2026-08-27',
          printVersion: 1,
          points: [point('bk-late', '2026-08-27T14:00:00Z', '17:00')],
        },
        {
          routeSheetId: 'rs-a',
          supplierId: 'sup-1',
          date: '2026-08-27',
          printVersion: 3,
          points: [point('bk-early', '2026-08-27T06:00:00Z', '09:00')],
        },
      ],
    } satisfies DriverRouteSheetResponse);

    const { sheet } = await promise;
    expect(sheet?.points.map((p) => p.bookingId)).toEqual(['bk-early', 'bk-late']);
    expect(sheet?.routeSheetIds).toEqual(['rs-b', 'rs-a']);
  });

  it('перелік дат збирається з того самого маршруту по горизонту (DRV-13)', async () => {
    const today = kyivDateKey();
    const promise = firstValueFrom(api.availableDates(today));

    const requests = http.match((r) => r.url === '/api/driver/v1/route-sheet');
    expect(requests.map((r) => r.request.params.get('date'))).toEqual([
      today,
      addDaysToDateKey(today, 1),
      addDaysToDateKey(today, 2),
    ]);

    requests[0].flush(
      envelope(today, [point('bk-1', '2026-08-27T06:00:00Z', '09:00')]),
    );
    requests[1].flush(envelope(addDaysToDateKey(today, 1), []));
    // Збій на окремій даті не ламає весь перелік.
    requests[2].flush('boom', { status: 500, statusText: 'Server Error' });

    expect(await promise).toEqual([{ date: today, pointCount: 1 }]);
  });
});

/**
 * Тести закріплюють РЕАЛЬНІ маршрути дій водія (BookingActionController):
 *   POST  /api/driver/v1/bookings/{id}/arrived
 *   POST  /api/driver/v1/bookings/{id}/delay
 *   PATCH /api/driver/v1/bookings/{id}
 */
describe('HttpDriverApi — дії контуру водія', () => {
  let api: DriverApi;
  let http: HttpTestingController;

  /** Відповідь дій — BookingPresenter::toArray() (тут лише потрібні поля). */
  const booking = (over: Record<string, unknown> = {}) => ({
    id: 'bk-1',
    status: 'arrived',
    orderId: null,
    arrivedAt: '2026-08-27T09:12:00Z',
    delayed: { flag: false, reason: null, eta: null },
    ...over,
  });

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: DriverApi, useClass: HttpDriverApi },
      ],
    });
    api = TestBed.inject(DriverApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('«На місці» — POST /bookings/{id}/arrived, id у відповіді стає bookingId', async () => {
    const promise = firstValueFrom(
      api.markArrived('bk-1', '2026-08-27T09:12:00Z'),
    );

    const request = http.expectOne(
      (r) => r.url === '/api/driver/v1/bookings/bk-1/arrived' && r.method === 'POST',
    );
    // Фактичний час натискання надсилається для офлайн-черги.
    expect(request.request.body).toEqual({ arrivedAt: '2026-08-27T09:12:00Z' });
    request.flush(booking());

    expect(await promise).toEqual({
      bookingId: 'bk-1',
      status: 'arrived',
      orderId: null,
      arrivedAt: '2026-08-27T09:12:00Z',
      delayed: { flag: false, reason: null, eta: null },
    });
  });

  it('ідемпотентна відповідь на вже позначеному бронюванні — успіх, не помилка', async () => {
    const promise = firstValueFrom(
      api.markArrived('bk-1', '2026-08-27T09:12:00Z'),
    );

    // Магазин відмітив прибуття першим: бекенд віддає 200 і поточний стан.
    http
      .expectOne((r) => r.url === '/api/driver/v1/bookings/bk-1/arrived')
      .flush(booking({ arrivedAt: '2026-08-27T08:55:00Z' }));

    const result = await promise;
    expect(result.status).toBe('arrived');
    expect(result.arrivedAt).toBe('2026-08-27T08:55:00Z');
  });

  it('затримка — POST /bookings/{id}/delay з причиною довідника та ETA', async () => {
    const promise = firstValueFrom(
      api.reportDelay('bk-1', {
        reason: 'затори',
        eta: '2026-08-27T10:00:00Z',
      }),
    );

    const request = http.expectOne(
      (r) => r.url === '/api/driver/v1/bookings/bk-1/delay' && r.method === 'POST',
    );
    // Порожній коментар не надсилається — поле необовʼязкове.
    expect(request.request.body).toEqual({
      reason: 'затори',
      eta: '2026-08-27T10:00:00Z',
    });
    request.flush(
      booking({
        status: 'booked',
        delayed: { flag: true, reason: 'затори', eta: '2026-08-27T10:00:00Z' },
      }),
    );

    expect((await promise).delayed.flag).toBe(true);
  });

  it('причина «інше» надсилає коментар', async () => {
    const promise = firstValueFrom(
      api.reportDelay('bk-1', {
        reason: 'інше',
        eta: '2026-08-27T10:00:00Z',
        comment: '  прокол колеса  ',
      }),
    );

    const request = http.expectOne(
      (r) => r.url === '/api/driver/v1/bookings/bk-1/delay',
    );
    expect(request.request.body).toEqual({
      reason: 'інше',
      eta: '2026-08-27T10:00:00Z',
      comment: 'прокол колеса',
    });
    request.flush(booking({ status: 'booked' }));

    await promise;
  });

  it('orderId — PATCH /bookings/{id} рівно з одним полем', async () => {
    const promise = firstValueFrom(api.updateOrderId('bk-1', '4410999'));

    const request = http.expectOne(
      (r) => r.url === '/api/driver/v1/bookings/bk-1' && r.method === 'PATCH',
    );
    // Будь-яке інше поле контролер відхилив би з 403 — надсилаємо лише orderId.
    expect(Object.keys(request.request.body as object)).toEqual(['orderId']);
    request.flush(booking({ status: 'booked', orderId: '4410999' }));

    expect((await promise).orderId).toBe('4410999');
  });

  it('редагування після початку розвантаження — 422 з поясненням бекенду', async () => {
    const promise = firstValueFrom(api.updateOrderId('bk-1', '4410999'));

    http.expectOne((r) => r.url === '/api/driver/v1/bookings/bk-1').flush(
      {
        code: 'VALIDATION_FAILED',
        detail: 'Номер замовлення можна вказати лише до початку розвантаження',
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );

    await expect(promise).rejects.toBeTruthy();
  });

  it('ідентифікатор бронювання екранується у шляху', async () => {
    const promise = firstValueFrom(api.updateOrderId('bk/1 2', null));

    http
      .expectOne((r) => r.url === '/api/driver/v1/bookings/bk%2F1%202')
      .flush(booking({ id: 'bk/1 2', status: 'booked' }));

    await promise;
  });
});
