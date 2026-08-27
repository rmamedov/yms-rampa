import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { HttpStoreGateway } from './http-store.gateway';
import { HttpAuthGateway } from './http-auth.gateway';
import { StoreGateway, AuthGateway } from './gateways';
import { WireAuthTokenResponse, WireBooking } from '../api/wire.model';
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

  it('читальні маршрути не б’ють у мережу, а повідомляють про брак бекенду', (done) => {
    gateway.getBoard('s-1', '2026-08-27').subscribe({
      error: (error: unknown) => {
        expect((error as AppError).code).toBe('STORE_READ_NOT_IMPLEMENTED');
        done();
      },
    });
    http.expectNone(() => true);
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
