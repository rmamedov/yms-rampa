import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { ArrivalQueueService, isRetryable } from './arrival-queue.service';
import { DriverApi } from '../data/driver.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import type {
  ArrivePayload,
  AvailableDate,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';

const POINT: RoutePoint = {
  bookingId: 'bk-0001',
  slotStart: '2026-08-27T09:00:00.000Z',
  slotEnd: '2026-08-27T10:00:00.000Z',
  store: {
    storeId: 'st-1',
    externalId: '1998',
    name: 'Сільпо №1998',
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
    latitude: 50.5,
    longitude: 30.5,
  },
  rampNumber: '2',
  orderId: null,
  pallets: 8,
  status: 'arrived',
  delayed: null,
  arrivedAt: '2026-08-27T09:05:00.000Z',
};

class FakeDriverApi extends DriverApi {
  readonly arriveCalls: Array<{ bookingId: string; payload: ArrivePayload }> = [];
  /** Черга відповідей: значення або помилка на кожен виклик arrive. */
  responses: Array<'ok' | unknown> = [];

  override availableDates(): Observable<readonly AvailableDate[]> {
    return of([]);
  }
  override routeSheet(): Observable<RouteSheet | null> {
    return of(null);
  }
  override setOrderId(): Observable<RoutePoint> {
    return of(POINT);
  }
  override setDelay(): Observable<RoutePoint> {
    return of(POINT);
  }
  override arrive(bookingId: string, payload: ArrivePayload): Observable<RoutePoint> {
    this.arriveCalls.push({ bookingId, payload });
    const next = this.responses.shift() ?? 'ok';
    if (next === 'ok') {
      return of({ ...POINT, bookingId });
    }
    return throwError(() => next);
  }
}

describe('ArrivalQueueService (DRV-34, DRV-35)', () => {
  let queue: ArrivalQueueService;
  let api: FakeDriverApi;
  let storage: LocalStorageService;

  beforeEach(() => {
    localStorage.clear();
    api = new FakeDriverApi();
    TestBed.configureTestingModule({
      providers: [{ provide: DriverApi, useValue: api }],
    });
    queue = TestBed.inject(ArrivalQueueService);
    storage = TestBed.inject(LocalStorageService);
  });

  it('кладе відмітку в чергу і зберігає її в localStorage', () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00.000Z');

    expect(queue.pendingCount()).toBe(1);
    expect(queue.isQueued('bk-1')).toBe(true);
    const persisted = storage.read<unknown[]>(STORAGE_KEYS.arrivalQueue, []);
    expect(persisted).toHaveLength(1);
    expect((persisted[0] as { pressedAt: string }).pressedAt).toBe(
      '2026-08-27T09:12:00.000Z',
    );
  });

  it('повторний enqueue не дублює запис і НЕ змінює зафіксований час натискання', () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00.000Z');
    const again = queue.enqueue('bk-1', '2026-08-27T09:40:00.000Z');

    expect(queue.pendingCount()).toBe(1);
    expect(again.pressedAt).toBe('2026-08-27T09:12:00.000Z');
  });

  it('відновлює чергу з localStorage при створенні сервісу', () => {
    storage.write(STORAGE_KEYS.arrivalQueue, [
      {
        bookingId: 'bk-9',
        pressedAt: '2026-08-27T08:00:00.000Z',
        latitude: null,
        longitude: null,
        attempts: 0,
      },
      { bookingId: 'сміття', pressedAt: 'не дата' },
    ]);

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: DriverApi, useValue: api }],
    });
    const restored = TestBed.inject(ArrivalQueueService);

    expect(restored.pendingCount()).toBe(1);
    expect(restored.isQueued('bk-9')).toBe(true);
  });

  it('при відправці передає ФАКТИЧНИЙ час натискання, а не час доставки', async () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00.000Z', {
      latitude: 50.1,
      longitude: 30.2,
    });

    const result = await queue.flush();

    expect(api.arriveCalls).toHaveLength(1);
    expect(api.arriveCalls[0].payload.pressedAt).toBe('2026-08-27T09:12:00.000Z');
    expect(api.arriveCalls[0].payload.latitude).toBe(50.1);
    expect(result.sent).toHaveLength(1);
    expect(queue.pendingCount()).toBe(0);
  });

  it('мережева помилка залишає відмітку в черзі для повторної спроби', async () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00.000Z');
    api.responses = [new ApiProblemError(0, { code: 'NETWORK_UNAVAILABLE' })];

    const result = await queue.flush();

    expect(result.retained).toEqual(['bk-1']);
    expect(queue.pendingCount()).toBe(1);
    expect(queue.queuedFor('bk-1')?.attempts).toBe(1);
  });

  it('409 від сервера (магазин відмітив першим) тихо очищає чергу', async () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00.000Z');
    api.responses = [
      new ApiProblemError(409, { code: 'BOOKING_ALREADY_ARRIVED' }),
    ];

    const result = await queue.flush();

    expect(result.discarded).toEqual(['bk-1']);
    expect(result.sent).toHaveLength(0);
    expect(queue.pendingCount()).toBe(0);
  });

  it('відправляє всі накопичені відмітки за один прохід', async () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00.000Z');
    queue.enqueue('bk-2', '2026-08-27T11:40:00.000Z');

    const result = await queue.flush();

    expect(api.arriveCalls.map((c) => c.bookingId)).toEqual(['bk-1', 'bk-2']);
    expect(result.sent).toHaveLength(2);
    expect(queue.pendingCount()).toBe(0);
  });

  it('isRetryable: мережа і 5xx — повторюємо, решта 4xx — ні', () => {
    expect(isRetryable(new ApiProblemError(0, {}))).toBe(true);
    expect(isRetryable(new ApiProblemError(503, {}))).toBe(true);
    expect(isRetryable(new ApiProblemError(429, {}))).toBe(true);
    expect(isRetryable(new ApiProblemError(409, {}))).toBe(false);
    expect(isRetryable(new ApiProblemError(422, {}))).toBe(false);
  });
});
