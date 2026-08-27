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
    localTime,
    slotStart,
    rampId: 'ramp-2',
    orderId: null,
    palletsCount: 8,
    plateNumber: 'AA 4721 OB',
    driverId: 'drv-1001',
    status: 'booked' as const,
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

    const sheet = await promise;
    expect(sheet?.points[0].bookingId).toBe('bk-1');
    expect(sheet?.routeSheetIds).toEqual(['rs-2026-08-27']);
  });

  it('порожній день бекенд віддає як 200 з routeSheets: [] → null', async () => {
    const promise = firstValueFrom(api.routeSheet('2026-08-28'));

    http
      .expectOne((r) => r.url === '/api/driver/v1/route-sheet')
      .flush(envelope('2026-08-28', []));

    expect(await promise).toBeNull();
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

    const sheet = await promise;
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
