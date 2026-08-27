import { TestBed } from '@angular/core/testing';
import { AuthService } from '../auth/auth.service';
import { TokenStorageService } from '../auth/token-storage.service';
import { AuthGateway, StoreGateway } from './gateways';
import { MockAuthGateway, MockStoreGateway } from './mock.gateways';
import { MockBackend } from './mock-backend.service';
import { BoardStore } from './board.store';
import { toKyivDateKey } from '../util/date.util';

/**
 * Мок-режим (environment.useMocks = true) має лишатися повністю робочим:
 * вхід → дошка магазину → дії над бронюванням, усе через ті самі шлюзи й
 * ті самі структури, що й реальний бекенд.
 */
describe('Мок-режим наскрізно', () => {
  const NOW = '2026-08-27T10:00:00.000Z';
  let auth: AuthService;
  let board: BoardStore;
  let backend: MockBackend;

  /** Мок-шлюзи імітують мережу через rxjs delay — прокручуємо таймери. */
  function tick(ms: number): void {
    jest.advanceTimersByTime(ms);
  }

  beforeEach(() => {
    jest.useFakeTimers();
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        TokenStorageService,
        MockBackend,
        { provide: AuthGateway, useClass: MockAuthGateway },
        { provide: StoreGateway, useClass: MockStoreGateway },
      ],
    });
    backend = TestBed.inject(MockBackend);
    backend.clock = () => NOW;
    auth = TestBed.inject(AuthService);
    board = TestBed.inject(BoardStore);
  });

  afterEach(() => {
    board.ngOnDestroy();
    jest.useRealTimers();
  });

  it('вхід дає профіль зі скоупом магазинів', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'demo' }).subscribe();
    tick(200);
    expect(auth.hasStoreAccess()).toBe(true);
    expect(auth.stores().length).toBe(2);
    // До завантаження конфігурації підписом лишається ідентифікатор філії.
    expect(auth.selectedStore()?.displayName).toBe(
      auth.selectedStore()?.storeId,
    );
  });

  it('дошка вантажиться і підписує магазин назвою з конфігурації', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'demo' }).subscribe();
    tick(200);
    board.setDate(toKyivDateKey(NOW));
    board.load();
    tick(500);

    expect(board.config()).not.toBeNull();
    expect(board.bookings().length).toBeGreaterThan(0);
    expect(board.ramps().length).toBeGreaterThanOrEqual(3);
    expect(auth.selectedStore()?.displayName).toContain('Сільпо');
    expect(board.stats().total).toBe(board.bookings().length);
  });

  it('дії магазину проходять через шлюз і оновлюють картку', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'demo' }).subscribe();
    tick(200);
    board.setDate(toKyivDateKey(NOW));
    board.load();
    tick(500);

    const booked = board.bookings().find((b) => b.status === 'booked');
    expect(booked).toBeDefined();

    board.markArrived(booked!);
    tick(500);
    expect(board.bookings().find((b) => b.id === booked!.id)?.status).toBe(
      'arrived',
    );

    board.startUnloading(booked!);
    tick(500);
    const unloading = board.bookings().find((b) => b.id === booked!.id);
    expect(unloading?.status).toBe('unloading');
    expect(unloading?.statusHistory.map((e) => e.to)).toEqual([
      'arrived',
      'unloading',
    ]);
  });

  it('walk-in додає бронювання у статусі arrived', () => {
    auth.login({ email: 'operator@silpo.ua', password: 'demo' }).subscribe();
    tick(200);
    board.setDate(toKyivDateKey(NOW));
    board.load();
    tick(500);

    const storeId = auth.selectedStore()!.storeId;
    const slot = backend.freeSlotsNow(storeId)[0];
    const before = board.bookings().length;

    board.createWalkIn({
      rampId: slot.rampId,
      slotStart: slot.slotStart,
      vehicle: { plateNumber: 'aa9999bb', weightTons: 5, brand: null },
      palletsCount: 8,
      supplierId: null,
      supplierName: 'ФОП Тест',
      orderId: null,
    });
    tick(500);

    expect(board.bookings().length).toBe(before + 1);
    const created = board.bookings().at(-1);
    expect(created?.type).toBe('walk_in');
    expect(created?.status).toBe('arrived');
    expect(created?.vehicle.plateNumber).toBe('AA9999BB');
    expect(created?.supplierName).toBe('ФОП Тест');
  });
});
