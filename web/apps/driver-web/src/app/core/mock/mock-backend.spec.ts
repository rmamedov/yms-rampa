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
      'bookingId',
      'city',
      'driverId',
      'localTime',
      'orderId',
      'palletsCount',
      'plateNumber',
      'rampId',
      'slotStart',
      'status',
      'storeName',
    ]);
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
