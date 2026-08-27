import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { RouteSheetApi } from '../api/contracts';
import { problemError, ERROR_CODES } from '../api/problem';
import type {
  RouteSheet,
  RouteSheetAssignment,
  RouteSheetPoint,
  RouteSheetSummary,
  UpcomingDelivery,
} from '../models/models';
import { RouteSheetsService, summaryOf } from './route-sheets.service';

const TODAY = '2026-03-12';
const NOW = new Date('2026-03-12T06:00:00Z');

function point(
  bookingId: string,
  slotStart: string,
  overrides: Partial<RouteSheetPoint> = {},
): RouteSheetPoint {
  return {
    bookingId,
    city: 'Київ',
    storeName: 'Сільпо №1998',
    address: 'просп. Володимира Івасюка, 46',
    localTime: slotStart.slice(11, 16),
    slotStart,
    rampId: 'r1',
    orderId: null,
    palletsCount: 10,
    plateNumber: 'АА1234ВС',
    driverId: 'drv-1',
    status: 'booked',
    ...overrides,
  };
}

function sheet(date: string, points: RouteSheetPoint[]): RouteSheet {
  return {
    routeSheetId: `rs-${date}`,
    supplierId: 'sup-1',
    supplierName: 'ТОВ «Агро-Логістик»',
    date,
    printVersion: 1,
    points,
  };
}

class StubRouteSheetApi extends RouteSheetApi {
  readonly requested: string[] = [];
  sheets = new Map<string, RouteSheet>();
  failing = new Set<string>();

  override detail(date: string): Observable<RouteSheet> {
    this.requested.push(date);
    if (this.failing.has(date)) {
      return throwError(() =>
        problemError(404, ERROR_CODES.notFound, 'Немає листа'),
      );
    }
    return of(this.sheets.get(date) ?? sheet(date, []));
  }

  override assignDriverToSheet(): Observable<RouteSheetAssignment> {
    throw new Error('не використовується');
  }

  override assignDriverToBooking(): Observable<RouteSheetAssignment> {
    throw new Error('не використовується');
  }
}

describe('RouteSheetsService — заміна відсутніх маршрутів бекенду', () => {
  let api: StubRouteSheetApi;
  let service: RouteSheetsService;

  beforeEach(() => {
    api = new StubRouteSheetApi();
    TestBed.configureTestingModule({
      providers: [{ provide: RouteSheetApi, useValue: api }],
    });
    service = TestBed.inject(RouteSheetsService);
  });

  it('питає лист на КОЖНУ дату діапазону — списку листів у бекенді немає', () => {
    service.summaries(TODAY, 1, 2).subscribe();
    expect(api.requested).toEqual([
      '2026-03-11',
      '2026-03-12',
      '2026-03-13',
      '2026-03-14',
    ]);
  });

  it('лишає у зведенні лише непорожні листи і позначає минулі архівними', () => {
    api.sheets.set(
      '2026-03-11',
      sheet('2026-03-11', [point('bk-1', '2026-03-11T08:00:00Z')]),
    );
    api.sheets.set(
      '2026-03-13',
      sheet('2026-03-13', [
        point('bk-2', '2026-03-13T08:00:00Z'),
        point('bk-3', '2026-03-13T09:00:00Z', { driverId: 'drv-2' }),
      ]),
    );

    let result: RouteSheetSummary[] = [];
    service.summaries(TODAY, 1, 1).subscribe((list) => (result = list));

    expect(result).toEqual([
      { date: '2026-03-11', pointsCount: 1, driverId: 'drv-1', archived: true },
      // Різні водії на точках — спільного водія в листа немає.
      { date: '2026-03-13', pointsCount: 2, driverId: null, archived: false },
    ]);
  });

  it('дата без листа не валить увесь діапазон', () => {
    api.failing.add('2026-03-12');
    api.sheets.set(
      '2026-03-13',
      sheet('2026-03-13', [point('bk-2', '2026-03-13T08:00:00Z')]),
    );

    let result: RouteSheetSummary[] = [];
    service.summaries(TODAY, 0, 1).subscribe((list) => (result = list));
    expect(result.map((s) => s.date)).toEqual(['2026-03-13']);
  });

  it('SUP-HOME-01: найближчі поставки — активні точки листів, відсортовані за часом', () => {
    api.sheets.set(
      TODAY,
      sheet(TODAY, [
        // Слот у минулому — на головну не потрапляє.
        point('bk-past', '2026-03-12T05:00:00Z'),
        point('bk-cancelled', '2026-03-12T10:00:00Z', { status: 'completed' }),
        point('bk-2', '2026-03-12T09:00:00Z'),
      ]),
    );
    api.sheets.set(
      '2026-03-13',
      sheet('2026-03-13', [point('bk-3', '2026-03-13T07:00:00Z')]),
    );

    let result: UpcomingDelivery[] = [];
    service.upcoming(10, TODAY, 2, NOW).subscribe((list) => (result = list));

    expect(result.map((p) => p.bookingId)).toEqual(['bk-2', 'bk-3']);
    expect(result[0].date).toBe(TODAY);
    expect(result[1].date).toBe('2026-03-13');
  });

  it('обмежує головну заданою кількістю карток', () => {
    api.sheets.set(
      TODAY,
      sheet(TODAY, [
        point('bk-1', '2026-03-12T08:00:00Z'),
        point('bk-2', '2026-03-12T09:00:00Z'),
      ]),
    );
    let result: UpcomingDelivery[] = [];
    service.upcoming(1, TODAY, 1, NOW).subscribe((list) => (result = list));
    expect(result.map((p) => p.bookingId)).toEqual(['bk-1']);
  });
});

describe('summaryOf', () => {
  it('віддає спільного водія листа лише якщо він один на всі точки', () => {
    expect(
      summaryOf(
        sheet(TODAY, [
          point('bk-1', '2026-03-12T08:00:00Z'),
          point('bk-2', '2026-03-12T09:00:00Z'),
        ]),
        TODAY,
      ).driverId,
    ).toBe('drv-1');

    expect(
      summaryOf(
        sheet(TODAY, [
          point('bk-1', '2026-03-12T08:00:00Z'),
          point('bk-2', '2026-03-12T09:00:00Z', { driverId: null }),
        ]),
        TODAY,
      ).driverId,
    ).toBeNull();
  });
});
