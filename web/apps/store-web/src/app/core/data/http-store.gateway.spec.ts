import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { HttpStoreGateway } from './http-store.gateway';
import { HttpAuthGateway } from './http-auth.gateway';
import {
  BoardSnapshot,
  StoreGateway,
  AuthGateway,
  WeekDaySlots,
} from './gateways';
import {
  WireAuthTokenResponse,
  WireBooking,
  WireSlot,
  WireStoreBrief,
  WireStoreConfig,
} from '../api/wire.model';
import { StoreScope } from '../models/auth.model';
import { Slot, StoreConfig, SupplierRef } from '../models/store.model';
import { AppError } from '../models/problem.model';

const BASE = '/api/store/v1';
const BOOKING_ID = 'bk-1';

const WIRE_BOOKING: WireBooking = {
  id: BOOKING_ID,
  type: 'scheduled',
  status: 'arrived',
  storeId: 's-1',
  store: {
    externalId: '1998',
    displayName: 'Сільпо №1998',
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
  },
  rampId: 'r1',
  slotStart: '2026-08-27T07:00:00Z',
  slotEnd: '2026-08-27T07:30:00Z',
  localDate: '2026-08-27',
  localTime: '10:00',
  supplierId: 'sp-01',
  supplierName: 'ТОВ «Молокія»',
  vehicle: { plateNumber: 'AA1234BB', weightTons: 5, brand: 'MAN' },
  driverId: 'dr-01',
  orderId: 'ORD-1001',
  palletsCount: 26,
  delayed: { flag: false, reason: null, eta: null },
  arrivedAt: '2026-08-27T06:50:00Z',
  unloadingStartedAt: null,
  completedAt: null,
  cancelledAt: null,
  cancellation: null,
  rejectedAt: null,
  unloadedPalletsCount: null,
  partialUnload: null,
  rescheduleOf: null,
  routeSheetId: null,
  createdBy: 'sp-01',
  createdAt: '2026-08-26T05:00:00Z',
  updatedAt: '2026-08-27T06:50:00Z',
  statusHistory: [
    { from: 'booked', to: 'arrived', at: '2026-08-27T06:50:00Z', by: 'dr-01' },
  ],
};

// --- Зрізи реальних відповідей стенду --------------------------------------

const WIRE_STORES: readonly WireStoreBrief[] = [
  {
    storeId: 'st-1',
    externalId: '2207',
    displayName: 'Сільпо, бульв. Кельнський, 1',
    city: 'Дніпро',
    address: 'бульв. Кельнський, 1',
    ymsStatus: 'active',
  },
  {
    storeId: 'st-2',
    externalId: '2262',
    displayName: 'Сільпо, бульв. Слави, 5',
    city: 'Дніпро',
    address: 'бульв. Слави, 5',
    ymsStatus: 'active',
  },
];

const WIRE_CONFIG: WireStoreConfig = {
  storeId: 'st-1',
  externalId: '2207',
  displayName: 'Сільпо, бульв. Кельнський, 1',
  city: 'Дніпро',
  address: 'бульв. Кельнський, 1',
  ramps: [
    { rampId: 'ramp-1', name: 'Рампа 1', active: true },
    { rampId: 'ramp-2', name: 'Рампа 2', active: false },
  ],
  slotSizeMinutes: 30,
  receivingWindows: [
    { dayOfWeek: 1, intervals: [{ from: '08:00', to: '14:00' }] },
    { dayOfWeek: 2, intervals: [{ from: '08:00', to: '14:00' }] },
  ],
  maxVehicleWeightTons: 20,
  noShowGraceMinutes: 30,
  leadTimeMinutes: 60,
  horizonDays: 14,
};

const WIRE_SLOTS: readonly WireSlot[] = [
  {
    rampId: 'ramp-1',
    slotStart: '2026-08-28T05:00:00Z',
    slotEnd: '2026-08-28T05:30:00Z',
    localStart: '08:00',
    state: 'booked',
    selectable: false,
    bookingId: 'bk-77',
  },
  {
    rampId: 'ramp-2',
    slotStart: '2026-08-28T05:00:00Z',
    slotEnd: '2026-08-28T05:30:00Z',
    localStart: '08:00',
    state: 'available',
    selectable: true,
    bookingId: null,
  },
  {
    rampId: 'ramp-2',
    slotStart: '2026-08-28T05:30:00Z',
    slotEnd: '2026-08-28T06:00:00Z',
    localStart: '08:30',
    state: 'reserved',
    selectable: false,
    bookingId: null,
    reservedForSupplierId: 'sp-09',
  },
  {
    rampId: 'ramp-1',
    slotStart: '2026-08-28T06:00:00Z',
    slotEnd: '2026-08-28T06:30:00Z',
    localStart: '09:00',
    state: 'blocked',
    selectable: false,
    bookingId: null,
    blockReason: 'санітарна година',
  },
];

/** Бронювання дошки: зі знімком водія і журналом обох поколінь. */
const WIRE_BOOKING_WITH_DRIVER: WireBooking = {
  ...WIRE_BOOKING,
  status: 'arrived',
  delayed: { flag: true, reason: 'затори', eta: '2026-08-28T07:15:00Z' },
  driver: {
    driverId: 'dr-01',
    fullName: 'Коваленко Петро',
    phone: '+380671234567',
    active: true,
  },
  statusHistory: [
    // Старий запис: ролі виконавця бекенд тоді не зберігав.
    {
      from: null,
      to: 'booked',
      at: '2026-08-26T05:00:00Z',
      by: 'sp-01',
      byRole: null,
      byContour: null,
      byLabel: null,
    },
    {
      from: 'booked',
      to: 'arrived',
      at: '2026-08-27T06:50:00Z',
      by: 'u-77',
      byRole: 'store_manager',
      byContour: 'staff',
      byLabel: 'Керівник магазину',
      meta: { source: 'store' },
    },
  ],
};

describe('HttpStoreGateway — контракт /api/store/v1', () => {
  let gateway: StoreGateway;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: StoreGateway, useClass: HttpStoreGateway },
      ],
    });
    gateway = TestBed.inject(StoreGateway);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function expectPost(url: string): ReturnType<HttpTestingController['expectOne']> {
    const request = http.expectOne(url);
    expect(request.request.method).toBe('POST');
    return request;
  }

  it('ST-01 «На місці» → POST /bookings/{id}/arrived', () => {
    let received: string | null = null;
    gateway.markArrived(BOOKING_ID).subscribe((b) => (received = b.id));
    expectPost(`${BASE}/bookings/${BOOKING_ID}/arrived`).flush(WIRE_BOOKING);
    expect(received).toBe(BOOKING_ID);
  });

  it('ST-02 → POST /bookings/{id}/unloading (не /start-unloading)', () => {
    gateway.startUnloading(BOOKING_ID).subscribe();
    expectPost(`${BASE}/bookings/${BOOKING_ID}/unloading`).flush(WIRE_BOOKING);
  });

  it('ST-03 → POST /bookings/{id}/completed з вкладеним partialUnload', () => {
    gateway
      .completeUnloading(BOOKING_ID, {
        unloadedPalletsCount: 20,
        partialUnload: { reason: 'бій/брак', comment: 'дві палети' },
      })
      .subscribe();
    const request = expectPost(`${BASE}/bookings/${BOOKING_ID}/completed`);
    expect(request.request.body).toEqual({
      unloadedPalletsCount: 20,
      partialUnload: { reason: 'бій/брак', comment: 'дві палети' },
    });
    request.flush(WIRE_BOOKING);
  });

  it('ST-03 без часткового розвантаження не надсилає partialUnload', () => {
    gateway
      .completeUnloading(BOOKING_ID, {
        unloadedPalletsCount: 26,
        partialUnload: null,
      })
      .subscribe();
    const request = expectPost(`${BASE}/bookings/${BOOKING_ID}/completed`);
    expect(request.request.body).toEqual({ unloadedPalletsCount: 26 });
    request.flush(WIRE_BOOKING);
  });

  it('NOSH-02 → POST /bookings/{id}/no-show без тіла-версії', () => {
    gateway.markNoShow(BOOKING_ID).subscribe();
    const request = expectPost(`${BASE}/bookings/${BOOKING_ID}/no-show`);
    expect(request.request.body).toEqual({});
    request.flush(WIRE_BOOKING);
  });

  it('ST-07 → POST /bookings/{id}/rejected із причиною довідника', () => {
    gateway
      .reject(BOOKING_ID, { reason: 'відсутні документи', comment: null })
      .subscribe();
    const request = expectPost(`${BASE}/bookings/${BOOKING_ID}/rejected`);
    expect(request.request.body).toEqual({
      reason: 'відсутні документи',
      comment: null,
    });
    request.flush(WIRE_BOOKING);
  });

  it('DLY-01 → POST (не PATCH) /bookings/{id}/delay з reason+eta', () => {
    gateway
      .setDelay(BOOKING_ID, {
        reason: 'затори',
        eta: '2026-08-27T08:15:00Z',
        comment: null,
      })
      .subscribe();
    const request = expectPost(`${BASE}/bookings/${BOOKING_ID}/delay`);
    expect(request.request.body).toEqual({
      reason: 'затори',
      eta: '2026-08-27T08:15:00Z',
      comment: null,
    });
    request.flush(WIRE_BOOKING);
  });

  it('EDIT-06 → POST /bookings/{id}/reassign (не /reassign-ramp)', () => {
    gateway.reassignRamp(BOOKING_ID, { rampId: 'r2' }).subscribe();
    const request = expectPost(`${BASE}/bookings/${BOOKING_ID}/reassign`);
    expect(request.request.body).toEqual({ rampId: 'r2' });
    request.flush(WIRE_BOOKING);
  });

  it('WALK-01 → POST /bookings/walk-in зі storeId і vehicle у тілі', () => {
    gateway
      .createWalkIn({
        storeId: 's-1',
        rampId: 'r3',
        slotStart: '2026-08-27T09:00:00Z',
        vehicle: { plateNumber: 'AA1234BB', weightTons: 7.5, brand: null },
        palletsCount: 10,
        supplierId: null,
        supplierName: 'ФОП Гуменюк В. П.',
        orderId: null,
      })
      .subscribe();
    const request = expectPost(`${BASE}/bookings/walk-in`);
    expect(request.request.body).toEqual({
      storeId: 's-1',
      rampId: 'r3',
      slotStart: '2026-08-27T09:00:00Z',
      vehicle: { plateNumber: 'AA1234BB', weightTons: 7.5, brand: null },
      palletsCount: 10,
      supplierId: null,
      supplierName: 'ФОП Гуменюк В. П.',
      orderId: null,
    });
    request.flush(WIRE_BOOKING, { status: 201, statusText: 'Created' });
  });

  it('розбирає problem+json бекенду в AppError із кодом', (done) => {
    gateway.markNoShow(BOOKING_ID).subscribe({
      error: (error: unknown) => {
        expect((error as AppError).code).toBe('INVALID_STATUS_TRANSITION');
        expect((error as AppError).status).toBe(409);
        done();
      },
    });
    expectPost(`${BASE}/bookings/${BOOKING_ID}/no-show`).flush(
      {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        code: 'INVALID_STATUS_TRANSITION',
        detail: 'Перехід зі статусу «completed» у «no_show» неможливий',
      },
      { status: 409, statusText: 'Conflict' },
    );
  });

  // -------------------------------------------------------------------------
  // Читання. Перевіряється і маршрут, і мапінг кожної відповіді у моделі.
  // Тіла відповідей — зрізи реального стенду.
  // -------------------------------------------------------------------------

  function expectGet(url: string): ReturnType<HttpTestingController['expectOne']> {
    const request = http.expectOne(url);
    expect(request.request.method).toBe('GET');
    return request;
  }

  it('GET /stores → перелік філій під StoreScope', () => {
    let received: readonly StoreScope[] = [];
    gateway.getStores().subscribe((stores) => (received = stores));

    expectGet(`${BASE}/stores`).flush(WIRE_STORES);

    expect(received).toEqual([
      {
        storeId: 'st-1',
        externalId: '2207',
        displayName: 'Сільпо, бульв. Кельнський, 1',
        city: 'Дніпро',
        address: 'бульв. Кельнський, 1',
      },
      {
        storeId: 'st-2',
        externalId: '2262',
        displayName: 'Сільпо, бульв. Слави, 5',
        city: 'Дніпро',
        address: 'бульв. Слави, 5',
      },
    ]);
  });

  it('GET /stores віддає перелік цілком, без зрізів', () => {
    const many = Array.from({ length: 30 }, (_, i) => ({
      storeId: `st-${i + 1}`,
      externalId: String(2200 + i),
      displayName: `Сільпо №${2200 + i}`,
      city: 'Київ',
      address: `вул. Тестова, ${i + 1}`,
      ymsStatus: 'active',
    }));
    let received: readonly StoreScope[] = [];
    gateway.getStores().subscribe((stores) => (received = stores));
    expectGet(`${BASE}/stores`).flush(many);

    expect(received).toHaveLength(30);
    expect(received.at(-1)?.externalId).toBe('2229');
  });

  it('GET /stores/{id}/config → StoreConfig із рампами і вікнами прийому', () => {
    let received: StoreConfig | null = null;
    gateway.getStoreConfig('st-1').subscribe((config) => (received = config));

    expectGet(`${BASE}/stores/st-1/config`).flush(WIRE_CONFIG);

    expect(received).toEqual(WIRE_CONFIG);
    const config = received as unknown as StoreConfig;
    expect(config.ramps.map((r) => r.rampId)).toEqual(['ramp-1', 'ramp-2']);
    expect(config.receivingWindows[0]).toEqual({
      dayOfWeek: 1,
      intervals: [{ from: '08:00', to: '14:00' }],
    });
    expect(config.slotSizeMinutes).toBe(30);
    expect(config.maxVehicleWeightTons).toBe(20);
    expect(config.horizonDays).toBe(14);
  });

  it('GET /stores/{id}/suppliers → повний довідник для walk-in', () => {
    const wire = Array.from({ length: 21 }, (_, i) => ({
      supplierId: `sup-${i + 1}`,
      name: `Постачальник ${i + 1}`,
    }));
    let received: readonly SupplierRef[] = [];
    gateway.getSuppliers('st-1').subscribe((list) => (received = list));

    expectGet(`${BASE}/stores/st-1/suppliers`).flush(wire);

    // Довідник не пагінований — жодного постачальника губити не можна.
    expect(received).toHaveLength(21);
    expect(received[0]).toEqual({ supplierId: 'sup-1', name: 'Постачальник 1' });
    expect(received.at(-1)).toEqual({
      supplierId: 'sup-21',
      name: 'Постачальник 21',
    });
  });

  it('GET /bookings?storeId=&date= → бронювання плюс серверний now', () => {
    let received: BoardSnapshot | null = null;
    gateway.getBoard('st-1', '2026-08-28').subscribe((s) => (received = s));

    const request = expectGet(
      `${BASE}/bookings?storeId=st-1&date=2026-08-28`,
    );
    expect(request.request.params.get('storeId')).toBe('st-1');
    expect(request.request.params.get('date')).toBe('2026-08-28');
    request.flush({
      storeId: 'st-1',
      date: '2026-08-28',
      now: '2026-08-28T06:30:00Z',
      bookings: [WIRE_BOOKING_WITH_DRIVER],
    });

    const snapshot = received as unknown as BoardSnapshot;
    expect(snapshot.now).toBe('2026-08-28T06:30:00Z');
    expect(snapshot.bookings).toHaveLength(1);
    const booking = snapshot.bookings[0];
    expect(booking.id).toBe(BOOKING_ID);
    expect(booking.type).toBe('scheduled');
    expect(booking.rampId).toBe('r1');
    expect(booking.supplierName).toBe('ТОВ «Молокія»');
    expect(booking.orderId).toBe('ORD-1001');
    expect(booking.palletsCount).toBe(26);
    expect(booking.delayed).toEqual({
      flag: true,
      reason: 'затори',
      eta: '2026-08-28T07:15:00Z',
    });
    expect(booking.arrivedAt).toBe('2026-08-27T06:50:00Z');
    // Знімок профілю водія поруч із голим driverId.
    expect(booking.driverId).toBe('dr-01');
    expect(booking.driver).toEqual({
      driverId: 'dr-01',
      fullName: 'Коваленко Петро',
      phone: '+380671234567',
      active: true,
    });
  });

  it('журнал дій отримує роль, контур і позначку виконавця поруч із by', () => {
    let received: BoardSnapshot | null = null;
    gateway.getBoard('st-1', '2026-08-28').subscribe((s) => (received = s));
    expectGet(`${BASE}/bookings?storeId=st-1&date=2026-08-28`).flush({
      storeId: 'st-1',
      date: '2026-08-28',
      now: '2026-08-28T06:30:00Z',
      bookings: [WIRE_BOOKING_WITH_DRIVER],
    });

    const history = (received as unknown as BoardSnapshot).bookings[0]
      .statusHistory;
    expect(history[0]).toEqual({
      from: null,
      to: 'booked',
      at: '2026-08-26T05:00:00Z',
      by: 'sp-01',
      // Запис зроблено до появи полів виконавця — чесне «невідомо».
      byRole: null,
      byContour: null,
      byLabel: null,
      meta: {},
    });
    expect(history[1]).toEqual({
      from: 'booked',
      to: 'arrived',
      at: '2026-08-27T06:50:00Z',
      by: 'u-77',
      byRole: 'store_manager',
      byContour: 'staff',
      byLabel: 'Керівник магазину',
      meta: { source: 'store' },
    });
  });

  it('GET /stores/{id}/slots?date= → сітка доби з bookingId клітинки', () => {
    let received: readonly Slot[] = [];
    gateway.getSlots('st-1', '2026-08-28').subscribe((s) => (received = s));

    const request = expectGet(
      `${BASE}/stores/st-1/slots?date=2026-08-28`,
    );
    expect(request.request.params.get('date')).toBe('2026-08-28');
    request.flush(WIRE_SLOTS);

    expect(received).toEqual([
      {
        rampId: 'ramp-1',
        slotStart: '2026-08-28T05:00:00Z',
        slotEnd: '2026-08-28T05:30:00Z',
        localStart: '08:00',
        state: 'booked',
        selectable: false,
        bookingId: 'bk-77',
        reservedForSupplierId: null,
        blockReason: null,
      },
      {
        rampId: 'ramp-2',
        slotStart: '2026-08-28T05:00:00Z',
        slotEnd: '2026-08-28T05:30:00Z',
        localStart: '08:00',
        state: 'available',
        selectable: true,
        bookingId: null,
        reservedForSupplierId: null,
        blockReason: null,
      },
      // Необовʼязкові поля бекенд додає лише за наявності значення.
      {
        rampId: 'ramp-2',
        slotStart: '2026-08-28T05:30:00Z',
        slotEnd: '2026-08-28T06:00:00Z',
        localStart: '08:30',
        state: 'reserved',
        selectable: false,
        bookingId: null,
        reservedForSupplierId: 'sp-09',
        blockReason: null,
      },
      {
        rampId: 'ramp-1',
        slotStart: '2026-08-28T06:00:00Z',
        slotEnd: '2026-08-28T06:30:00Z',
        localStart: '09:00',
        state: 'blocked',
        selectable: false,
        bookingId: null,
        reservedForSupplierId: null,
        blockReason: 'санітарна година',
      },
    ]);
  });

  it('GET /stores/{id}/slots?from=&days=7 → тиждень діб із ключем дати', () => {
    let received: readonly WeekDaySlots[] = [];
    gateway.getWeek('st-1', '2026-08-24').subscribe((w) => (received = w));

    const request = expectGet(
      `${BASE}/stores/st-1/slots?from=2026-08-24&days=7`,
    );
    expect(request.request.params.get('from')).toBe('2026-08-24');
    expect(request.request.params.get('days')).toBe('7');
    request.flush(
      Array.from({ length: 7 }, (_, i) => ({
        dateKey: `2026-08-${24 + i}`,
        slots: i === 6 ? [] : WIRE_SLOTS.slice(0, 2),
      })),
    );

    expect(received).toHaveLength(7);
    expect(received.map((d) => d.dateKey)).toEqual([
      '2026-08-24',
      '2026-08-25',
      '2026-08-26',
      '2026-08-27',
      '2026-08-28',
      '2026-08-29',
      '2026-08-30',
    ]);
    expect(received[0].slots[0].localStart).toBe('08:00');
    expect(received[0].slots[0].bookingId).toBe('bk-77');
    // Вихідний без вікна прийому — доба є, слотів немає.
    expect(received[6].slots).toEqual([]);
  });

  it('помилка читання приходить як AppError із кодом бекенду', (done) => {
    gateway.getBoard('чужий', '2026-08-28').subscribe({
      error: (error: unknown) => {
        expect((error as AppError).code).toBe('ACCESS_DENIED');
        expect((error as AppError).status).toBe(403);
        done();
      },
    });
    expectGet(`${BASE}/bookings?storeId=%D1%87%D1%83%D0%B6%D0%B8%D0%B9&date=2026-08-28`).flush(
      {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
        code: 'ACCESS_DENIED',
        detail: 'Немає доступу до цього магазину',
      },
      { status: 403, statusText: 'Forbidden' },
    );
  });
});

describe('HttpAuthGateway — контракт /api/store/v1/auth', () => {
  let gateway: AuthGateway;
  let http: HttpTestingController;

  const TOKENS: WireAuthTokenResponse = {
    tokenType: 'Bearer',
    accessToken: 'a1',
    expiresIn: 900,
    accessExpiresAt: '2026-08-27T10:15:00+00:00',
    refreshToken: 'r1',
    refreshExpiresAt: '2026-09-26T10:00:00+00:00',
    sessionId: 'sess-1',
    user: {
      id: 'u-1',
      email: 'operator@silpo.ua',
      fullName: 'Оксана Литвин',
      role: 'store_operator',
      roleLabel: 'Приймальник магазину',
      scope: { storeIds: ['s-1'], networkWide: false },
      twoFactorEnabled: false,
      permissions: ['booking.read.all'],
    },
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthGateway, useClass: HttpAuthGateway },
      ],
    });
    gateway = TestBed.inject(AuthGateway);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('логін надсилає саме поле email і розбирає плоску відповідь', () => {
    let profileEmail: string | null = null;
    let accessToken: string | null = null;
    gateway
      .login({ email: 'operator@silpo.ua', password: 'secret' })
      .subscribe((response) => {
        profileEmail = response.profile.email;
        accessToken = response.tokens.accessToken;
      });

    const request = http.expectOne(`${BASE}/auth/login`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      email: 'operator@silpo.ua',
      password: 'secret',
    });
    request.flush(TOKENS);

    expect(profileEmail).toBe('operator@silpo.ua');
    expect(accessToken).toBe('a1');
  });

  it('refresh повертає ту саму структуру, що й login', () => {
    let storeIds: readonly string[] = [];
    gateway
      .refresh('r1')
      .subscribe((response) => (storeIds = response.profile.storeIds));
    const request = http.expectOne(`${BASE}/auth/refresh`);
    expect(request.request.body).toEqual({ refreshToken: 'r1' });
    request.flush(TOKENS);
    expect(storeIds).toEqual(['s-1']);
  });

  it('logout надсилає refreshToken і приймає 204', () => {
    gateway.logout('r1').subscribe();
    const request = http.expectOne(`${BASE}/auth/logout`);
    expect(request.request.body).toEqual({ refreshToken: 'r1' });
    request.flush(null, { status: 204, statusText: 'No Content' });
  });
});
