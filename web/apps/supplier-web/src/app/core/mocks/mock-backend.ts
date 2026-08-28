import { environment } from '../../../environments/environment';
import {
  ERROR_CODES,
  problemError,
  type ApiProblemError,
} from '../api/problem';
import type {
  AuthSession,
  Booking,
  BookingReassign,
  BranchItem,
  CityItem,
  CreateBookingRequest,
  Driver,
  DriverCredentials,
  DriverInput,
  HoldSession,
  NetworkSettings,
  Ramp,
  RouteSheet,
  RouteSheetAssignment,
  RouteSheetPoint,
  SlotGrid,
  SlotKey,
  SupplierAccount,
  Vehicle,
  VehicleInput,
} from '../models/models';
import {
  addDays,
  diffDays,
  kyivDateIso,
  kyivTimeHm,
  utcIso,
} from '../util/kyiv-time';
import { extendedExpiry, HOLD_MAX_MINUTES, HOLD_TTL_MINUTES } from '../util/hold';
import {
  normalizePhone,
  normalizePlate,
  validatePallets,
  validateWeightTons,
} from '../util/validation';
import {
  buildSlotGrid,
  type EngineBlock,
  type EngineBooking,
  type EngineReserve,
  type ReceivingWindow,
  type SlotEngineStore,
} from './slot-engine';
import { RAW_BRANCH_ROWS } from './branches.fixture';

/** Магазин у мок-довіднику: конфігурація + видимість для постачальника. */
export interface MockStore extends SlotEngineStore {
  readonly externalId: string;
  readonly name: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: number;
  readonly longitude: number;
  readonly phone: string | null;
  /** STC-04 / DATA-08: постачальник бачить лише active + visibleToSuppliers. */
  readonly ymsActive: boolean;
  readonly visibleToSuppliers: boolean;
  readonly blocks: readonly EngineBlock[];
  readonly reserves: readonly EngineReserve[];
}

interface MockHold {
  token: string;
  storeId: string;
  rampId: string;
  slotStart: string;
  expiresAt: number;
  maxExpiresAt: number;
}

interface LoginAttempts {
  count: number;
  lockedUntil: number;
}

const ACTIVE_STATUSES = new Set(['booked', 'arrived', 'unloading']);
const LOCK_SECONDS = 900;

// Порожні значення — лише щоб задовольнити типи: мок-бекенд працює виключно
// при useMocks=true, а там demoLogin заданий. Літерала з паролем тут свідомо
// немає, інакше він потрапив би і в прод-бандл.
export const DEMO_CREDENTIALS = environment.demoLogin ?? { email: '', password: '' };

const DEFAULT_SETTINGS: NetworkSettings = {
  holdTtlMinutes: HOLD_TTL_MINUTES,
  holdMaxMinutes: HOLD_MAX_MINUTES,
  maxActiveBookingsPerSupplier: 50,
  defaultVisibleDays: 7,
};

export function hashString(value: string): number {
  let hash = 5381;
  for (let i = 0; i < value.length; i++) {
    hash = ((hash << 5) + hash + value.charCodeAt(i)) >>> 0;
  }
  return hash;
}

const WINDOW_PRESETS: ReceivingWindow[][] = [
  [{ from: '08:00', to: '14:00' }],
  [
    { from: '06:00', to: '12:00' },
    { from: '13:00', to: '18:00' },
  ],
  [{ from: '08:00', to: '20:00' }],
];

const MAX_WEIGHTS = [3.5, 5, 10, 20, 40];

const SUPPLIER_ID = 'sup-1';
const SUPPLIER_NAME = 'ТОВ «Агро-Логістик»';

function buildStore(
  externalId: string,
  city: string,
  address: string,
  latitude: number,
  longitude: number,
  open: boolean,
  index: number,
): MockStore {
  const storeId = `st-${index}-${externalId}`;
  const h = hashString(storeId);
  const rampCount = 2 + (h % 4);
  const ramps: Ramp[] = Array.from({ length: rampCount }, (_, i) => ({
    rampId: `${storeId}-r${i + 1}`,
    number: i + 1,
    name: String(i + 1),
  }));
  const windows = WINDOW_PRESETS[h % WINDOW_PRESETS.length];
  const ymsActive = open && h % 29 !== 0 && h % 31 !== 0;

  const blocks: EngineBlock[] =
    h % 11 === 0
      ? [{ rampId: null, from: '00:00', to: '23:59', reason: 'Інвентаризація' }]
      : h % 7 === 0
        ? [{ rampId: ramps[1].rampId, from: '10:00', to: '12:00' }]
        : [];

  const reserves: EngineReserve[] =
    h % 5 === 0
      ? [{ rampId: ramps[0].rampId, from: '08:00', to: '10:00', mine: true }]
      : h % 5 === 1
        ? [
            {
              rampId: ramps[ramps.length - 1].rampId,
              from: '12:00',
              to: '14:00',
              mine: false,
            },
          ]
        : [];

  return {
    storeId,
    externalId,
    name: `Сільпо №${externalId}`,
    city,
    address,
    latitude,
    longitude,
    phone: null,
    ymsActive,
    visibleToSuppliers: h % 13 !== 0,
    ramps,
    windows,
    slotSizeMinutes: h % 4 === 0 ? 60 : 30,
    leadTimeMinutes: 60,
    bookingHorizonDays: h % 9 === 0 ? 21 : 14,
    maxVehicleWeightTons: MAX_WEIGHTS[h % MAX_WEIGHTS.length],
    blocks,
    reserves,
  };
}

/**
 * In-memory двійник бекенду YMS для роботи без сервера (environment.useMocks).
 *
 * Повертає РІВНО ті самі структури, що й реальні контролери
 * `/api/supplier/v1/...`, і кидає ті самі коди помилок RFC 7807 —
 * інакше моки знову розійдуться з дійсністю. Методи синхронні, тому
 * бізнес-правила покриваються юніт-тестами без Angular і HTTP.
 */
/** StorePolicy::editDeadlineHours у демо-конфігурації. */
const MOCK_EDIT_DEADLINE_HOURS = 2;

export class MockBackend {
  readonly settings: NetworkSettings;
  private readonly stores: MockStore[];
  private readonly storeById = new Map<string, MockStore>();
  private readonly holds = new Map<string, MockHold>();
  private readonly holdsByToken = new Map<string, MockHold>();
  private readonly attempts = new Map<string, LoginAttempts>();
  /** Водій, призначений на весь лист (RouteSheetEntry.driverId). */
  private readonly sheetIds = new Map<string, string>();
  private vehicles: Vehicle[] = [];
  private drivers: Driver[] = [];
  private bookings: Booking[] = [];
  private sequence = 1;

  readonly profile: SupplierAccount = {
    accountId: 'acc-1',
    login: DEMO_CREDENTIALS.email,
    role: 'supplier_admin',
    contour: 'partner',
    supplierId: SUPPLIER_ID,
    driverId: null,
    mustChangePassword: false,
  };

  constructor(
    private readonly clock: () => Date = () => new Date(),
    settings: Partial<NetworkSettings> = {},
  ) {
    this.settings = { ...DEFAULT_SETTINGS, ...settings };
    this.stores = RAW_BRANCH_ROWS.map((row, index) =>
      buildStore(row[0], row[1], row[2], row[3], row[4], row[5] === 1, index),
    );
    for (const store of this.stores) {
      this.storeById.set(store.storeId, store);
    }
    this.seedVehicles();
    this.seedDrivers();
    this.seedBookings();
  }

  now(): Date {
    return this.clock();
  }

  // ── Автентифікація ──────────────────────────────────────────────────────

  login(login: string, password: string): AuthSession {
    const key = login.trim().toLowerCase();
    const attempt = this.attempts.get(key);
    const nowMs = this.now().getTime();
    if (attempt && attempt.lockedUntil > nowMs) {
      throw problemError(
        429,
        ERROR_CODES.authAccountLocked,
        'Забагато невдалих спроб входу. Спробуйте пізніше',
        { retryAfter: LOCK_SECONDS },
      );
    }
    // DRV-10: роль driver у supplier-web не пускають, причину не розкривають.
    const wrongClient = /^\+?3?8?0\d{9}$/.test(key.replace(/[\s()-]/g, ''));
    if (
      wrongClient ||
      key !== DEMO_CREDENTIALS.email ||
      password !== DEMO_CREDENTIALS.password
    ) {
      const next: LoginAttempts = {
        count: (attempt?.count ?? 0) + 1,
        lockedUntil: 0,
      };
      if (next.count >= 5) {
        next.lockedUntil = nowMs + LOCK_SECONDS * 1000;
        next.count = 0;
        this.attempts.set(key, next);
        throw problemError(
          429,
          ERROR_CODES.authAccountLocked,
          'Забагато невдалих спроб входу. Спробуйте пізніше',
          { retryAfter: LOCK_SECONDS },
        );
      }
      this.attempts.set(key, next);
      throw problemError(
        401,
        ERROR_CODES.authInvalidCredentials,
        'Невірний логін або пароль',
      );
    }
    this.attempts.delete(key);
    return this.issueSession();
  }

  refresh(refreshToken: string): AuthSession {
    if (!refreshToken.startsWith('mock-refresh')) {
      throw problemError(
        401,
        ERROR_CODES.authTokenInvalid,
        'Сесія завершилась',
      );
    }
    return this.issueSession();
  }

  logout(refreshToken: string): void {
    if (!refreshToken) {
      throw problemError(
        422,
        ERROR_CODES.validationFailed,
        'Поле «refreshToken» обовʼязкове',
      );
    }
  }

  private issueSession(): AuthSession {
    const now = this.now().getTime();
    return {
      accessToken: `mock-access.${now}`,
      accessExpiresAt: utcIso(new Date(now + 15 * 60000)),
      expiresIn: 900,
      refreshToken: `mock-refresh.${now}`,
      refreshExpiresAt: utcIso(new Date(now + 30 * 86400000)),
      tokenType: 'Bearer',
      profile: this.profile,
    };
  }

  // ── Довідник міст і філій ───────────────────────────────────────────────

  private visibleStores(): MockStore[] {
    return this.stores.filter(
      (store) => store.ymsActive && store.visibleToSuppliers,
    );
  }

  cities(): CityItem[] {
    const counts = new Map<string, number>();
    for (const store of this.visibleStores()) {
      counts.set(store.city, (counts.get(store.city) ?? 0) + 1);
    }
    return [...counts.entries()]
      .map(([city, storeCount]) => ({ city, storeCount }))
      .sort((a, b) => a.city.localeCompare(b.city, 'uk'));
  }

  branches(city: string): BranchItem[] {
    return this.visibleStores()
      .filter((store) => store.city === city)
      .sort((a, b) => a.address.localeCompare(b.address, 'uk'))
      .map((store) => this.toBranch(store));
  }

  branch(storeId: string): BranchItem {
    return this.toBranch(this.requireStore(storeId));
  }

  private toBranch(store: MockStore): BranchItem {
    return {
      storeId: store.storeId,
      externalId: store.externalId,
      name: store.name,
      city: store.city,
      address: store.address,
      latitude: store.latitude,
      longitude: store.longitude,
      phone: store.phone,
      ramps: store.ramps,
      maxVehicleWeightTons: store.maxVehicleWeightTons,
      slotSizeMinutes: store.slotSizeMinutes,
      leadTimeMinutes: store.leadTimeMinutes,
      bookingHorizonDays: store.bookingHorizonDays,
    };
  }

  /** STC-04 / DATA-08: невидимий магазин для постачальника просто не існує. */
  private requireStore(storeId: string): MockStore {
    const store = this.storeById.get(storeId);
    if (!store || !store.ymsActive || !store.visibleToSuppliers) {
      throw problemError(
        404,
        ERROR_CODES.storeNotFound,
        `Магазин ${storeId} не знайдено`,
      );
    }
    return store;
  }

  // ── Сітка слотів ────────────────────────────────────────────────────────

  slots(storeId: string, date: string): SlotGrid {
    const store = this.requireStore(storeId);
    const distance = diffDays(kyivDateIso(this.now()), date);
    if (distance < 0 || distance > store.bookingHorizonDays) {
      throw problemError(
        422,
        ERROR_CODES.dateOutOfHorizon,
        `Бронювання доступне не далі ніж на ${store.bookingHorizonDays} днів вперед`,
        { horizonDays: store.bookingHorizonDays },
      );
    }
    return this.buildGrid(store, date);
  }

  private buildGrid(store: MockStore, date: string): SlotGrid {
    const now = this.now();
    const booked: EngineBooking[] = this.bookings
      .filter((b) => b.storeId === store.storeId && ACTIVE_STATUSES.has(b.status))
      .map((b) => ({ id: b.id, rampId: b.rampId, slotStart: b.slotStart }));
    const holds = [...this.holds.values()]
      .filter((hold) => hold.storeId === store.storeId)
      .map((hold) => ({
        rampId: hold.rampId,
        slotStart: hold.slotStart,
        expiresAt: utcIso(new Date(hold.expiresAt)),
      }));

    const grid = buildSlotGrid({
      store,
      date,
      now,
      bookings: booked,
      holds,
      blocks: store.blocks,
      reserves: store.reserves,
    });

    // Бронювання інших постачальників — детерміновані, ~30% слотів.
    return {
      ...grid,
      slots: grid.slots.map((slot) =>
        slot.state === 'available' &&
        !slot.reservedForYou &&
        this.isForeignBooked(store.storeId, slot.rampId, slot.slotStart)
          ? { ...slot, state: 'booked' as const, selectable: false }
          : slot,
      ),
    };
  }

  /** Слот, зайнятий іншим постачальником (детерміновано за ключем слота). */
  isForeignBooked(storeId: string, rampId: string, slotStart: string): boolean {
    return hashString(`${storeId}|${rampId}|${slotStart}`) % 10 < 3;
  }

  // ── Холди ───────────────────────────────────────────────────────────────

  private slotKey(key: SlotKey): string {
    return `${key.storeId}|${key.rampId}|${key.slotStart}`;
  }

  private purgeHolds(): void {
    const nowMs = this.now().getTime();
    for (const [key, hold] of [...this.holds.entries()]) {
      if (hold.expiresAt <= nowMs) {
        this.holds.delete(key);
        this.holdsByToken.delete(hold.token);
      }
    }
  }

  hold(key: SlotKey): HoldSession {
    this.purgeHolds();
    const store = this.requireStore(key.storeId);
    const mapKey = this.slotKey(key);
    if (this.holds.has(mapKey)) {
      throw problemError(
        409,
        ERROR_CODES.slotHeld,
        'Слот зараз оформлює інший користувач. Спробуйте за кілька хвилин',
      );
    }
    if (this.isSlotTaken(store, key)) {
      throw problemError(
        409,
        ERROR_CODES.slotAlreadyBooked,
        'Слот щойно забронював інший постачальник',
        { ...key },
      );
    }
    const nowMs = this.now().getTime();
    const hold: MockHold = {
      token: `hold-${this.sequence++}`,
      storeId: key.storeId,
      rampId: key.rampId,
      slotStart: key.slotStart,
      expiresAt: nowMs + this.settings.holdTtlMinutes * 60000,
      maxExpiresAt: nowMs + this.settings.holdMaxMinutes * 60000,
    };
    this.holds.set(mapKey, hold);
    this.holdsByToken.set(hold.token, hold);
    return this.toHoldSession(hold);
  }

  /** HOLD-02: продовження вимагає і ключ слота, і holdToken. */
  extendHold(key: SlotKey, token: string): HoldSession {
    this.purgeHolds();
    const hold = this.holds.get(this.slotKey(key));
    if (!hold) {
      throw problemError(
        409,
        ERROR_CODES.holdExpired,
        'Час на оформлення вийшов. Оберіть слот ще раз',
      );
    }
    if (hold.token !== token) {
      throw problemError(
        409,
        ERROR_CODES.holdNotOwned,
        'Холд належить іншому користувачеві',
      );
    }
    const now = this.now();
    hold.expiresAt = extendedExpiry(
      now,
      new Date(hold.maxExpiresAt),
      this.settings.holdTtlMinutes,
    ).getTime();
    return this.toHoldSession(hold);
  }

  releaseHold(key: SlotKey, token: string): void {
    const mapKey = this.slotKey(key);
    const hold = this.holds.get(mapKey);
    if (!hold || hold.token !== token) {
      return;
    }
    this.holds.delete(mapKey);
    this.holdsByToken.delete(token);
  }

  private toHoldSession(hold: MockHold): HoldSession {
    const nowMs = this.now().getTime();
    return {
      holdToken: hold.token,
      storeId: hold.storeId,
      rampId: hold.rampId,
      slotStart: hold.slotStart,
      expiresAt: utcIso(new Date(hold.expiresAt)),
      maxExpiresAt: utcIso(new Date(hold.maxExpiresAt)),
      secondsLeft: Math.max(0, Math.floor((hold.expiresAt - nowMs) / 1000)),
    };
  }

  private isSlotTaken(store: MockStore, key: SlotKey): boolean {
    const own = this.bookings.some(
      (b) =>
        b.storeId === key.storeId &&
        b.rampId === key.rampId &&
        b.slotStart === key.slotStart &&
        ACTIVE_STATUSES.has(b.status),
    );
    return own || this.isForeignBooked(store.storeId, key.rampId, key.slotStart);
  }

  // ── Бронювання ──────────────────────────────────────────────────────────

  activeFutureBookings(): Booking[] {
    const nowMs = this.now().getTime();
    return this.bookings.filter(
      (b) =>
        ACTIVE_STATUSES.has(b.status) &&
        new Date(b.slotStart).getTime() > nowMs,
    );
  }

  createBooking(request: CreateBookingRequest): Booking {
    return this.schedule(request, null);
  }

  /** EDIT-01: перенесення — нове бронювання + атомарне скасування старого. */
  reschedule(bookingId: string, request: CreateBookingRequest): Booking {
    const source = this.requireBooking(bookingId);
    if (source.status !== 'booked') {
      throw problemError(
        422,
        ERROR_CODES.transitionNotAllowed,
        'Перенести можна лише заброньований слот',
      );
    }
    return this.schedule(request, source);
  }

  private schedule(
    request: CreateBookingRequest,
    source: Booking | null,
  ): Booking {
    this.purgeHolds();
    const store = this.requireStore(request.storeId);

    const vehicle = this.normalizeVehicle(request.vehicle);
    if (vehicle.weightTons > store.maxVehicleWeightTons) {
      throw problemError(
        422,
        ERROR_CODES.vehicleTooHeavy,
        `Ця філія приймає авто до ${store.maxVehicleWeightTons} т`,
        {
          maxVehicleWeightTons: store.maxVehicleWeightTons,
          actualWeightTons: vehicle.weightTons,
        },
      );
    }
    if (validatePallets(request.palletsCount)) {
      throw problemError(
        422,
        ERROR_CODES.palletsOutOfRange,
        `Кількість палет має бути в діапазоні 1..33, отримано ${request.palletsCount}`,
      );
    }

    const now = this.now();
    const slotStartMs = new Date(request.slotStart).getTime();
    const distance = diffDays(
      kyivDateIso(now),
      kyivDateIso(new Date(slotStartMs)),
    );
    if (distance > store.bookingHorizonDays) {
      throw problemError(
        422,
        ERROR_CODES.dateOutOfHorizon,
        `Бронювання доступне не далі ніж на ${store.bookingHorizonDays} днів вперед`,
        { horizonDays: store.bookingHorizonDays },
      );
    }
    if (slotStartMs < now.getTime() + store.leadTimeMinutes * 60000) {
      throw problemError(
        409,
        ERROR_CODES.slotNotAvailable,
        `Бронювання можливе не пізніше ніж за ${store.leadTimeMinutes} хв до початку слоту`,
        { slotState: 'past' },
      );
    }

    const limit = this.settings.maxActiveBookingsPerSupplier;
    const activeCount = this.activeFutureBookings().length - (source ? 1 : 0);
    if (activeCount >= limit) {
      throw problemError(
        422,
        ERROR_CODES.bookingLimitExceeded,
        `Досягнуто ліміт активних бронювань (${limit}). Скасуйте неактуальні бронювання або дочекайтеся виконання поточних`,
        { limit },
      );
    }

    const key: SlotKey = {
      storeId: request.storeId,
      rampId: request.rampId,
      slotStart: request.slotStart,
    };
    if (this.isSlotTaken(store, key)) {
      this.dropHold(key);
      throw problemError(
        409,
        ERROR_CODES.slotAlreadyBooked,
        'Слот щойно забронював інший постачальник',
        { ...key },
      );
    }

    if (!request.confirmConflict) {
      const conflict = this.findVehicleTimeConflict(
        vehicle.plateNumber,
        request.slotStart,
        store.slotSizeMinutes,
        source?.id,
      );
      if (conflict) {
        throw problemError(
          409,
          ERROR_CODES.vehicleTimeConflict,
          `Авто ${vehicle.plateNumber} уже має бронювання, що перетинається за часом. Підтвердіть, щоб продовжити`,
          {
            warning: true,
            plateNumber: vehicle.plateNumber,
            conflicts: [
              { bookingId: conflict.id, slotStart: conflict.slotStart },
            ],
          },
        );
      }
    }

    const localDate = kyivDateIso(new Date(slotStartMs));
    const booking: Booking = {
      id: `bk-${this.sequence++}`,
      type: 'scheduled',
      status: 'booked',
      storeId: store.storeId,
      store: {
        externalId: store.externalId,
        displayName: store.name,
        city: store.city,
        address: store.address,
      },
      rampId: request.rampId,
      slotStart: request.slotStart,
      slotEnd: utcIso(new Date(slotStartMs + store.slotSizeMinutes * 60000)),
      localDate,
      localTime: kyivTimeHm(new Date(slotStartMs)),
      supplierId: SUPPLIER_ID,
      supplierName: SUPPLIER_NAME,
      vehicle: {
        plateNumber: vehicle.plateNumber,
        weightTons: vehicle.weightTons,
        brand: vehicle.brand ?? null,
      },
      driverId: request.driverId ?? source?.driverId ?? null,
      orderId: request.orderId?.trim() || null,
      palletsCount: request.palletsCount,
      delayed: { flag: false, reason: null, eta: null },
      rescheduleOf: source?.id ?? null,
      routeSheetId: this.ensureSheetId(localDate),
      createdAt: utcIso(now),
      updatedAt: utcIso(now),
    };

    if (source) {
      this.replaceBooking(source.id, {
        ...source,
        status: 'cancelled',
        updatedAt: utcIso(now),
      });
    }
    this.bookings = [...this.bookings, booking];
    if (request.holdToken) {
      this.dropHold(key);
    }
    return booking;
  }

  private dropHold(key: SlotKey): void {
    const mapKey = this.slotKey(key);
    const hold = this.holds.get(mapKey);
    if (hold) {
      this.holds.delete(mapKey);
      this.holdsByToken.delete(hold.token);
    }
  }

  private findVehicleTimeConflict(
    plateNumber: string,
    slotStart: string,
    slotSizeMinutes: number,
    excludeBookingId?: string,
  ): Booking | undefined {
    const start = new Date(slotStart).getTime();
    const end = start + slotSizeMinutes * 60000;
    return this.bookings.find((b) => {
      if (b.id === excludeBookingId || !ACTIVE_STATUSES.has(b.status)) {
        return false;
      }
      if (b.vehicle.plateNumber !== plateNumber) {
        return false;
      }
      const bStart = new Date(b.slotStart).getTime();
      const bEnd = new Date(b.slotEnd).getTime();
      return bStart < end && bEnd > start;
    });
  }

  private normalizeVehicle(input: VehicleInput): VehicleInput {
    const plateNumber = normalizePlate(input.plateNumber);
    if (plateNumber.length < 4 || plateNumber.length > 12) {
      throw problemError(
        422,
        ERROR_CODES.invalidPlateNumber,
        `Некоректний держномер: ${input.plateNumber}`,
      );
    }
    if (!(input.weightTons > 0)) {
      throw problemError(
        422,
        ERROR_CODES.validationFailed,
        'Поле «vehicle.weightTons» обовʼязкове',
      );
    }
    return { ...input, plateNumber };
  }

  booking(bookingId: string): Booking {
    return this.requireBooking(bookingId);
  }

  cancelBooking(bookingId: string, reason?: string): Booking {
    const booking = this.requireBooking(bookingId);
    if (!ACTIVE_STATUSES.has(booking.status)) {
      throw problemError(
        409,
        ERROR_CODES.transitionNotAllowed,
        'Бронювання вже не можна скасувати',
      );
    }
    void reason;
    const updated: Booking = {
      ...booking,
      status: 'cancelled',
      updatedAt: utcIso(this.now()),
    };
    this.replaceBooking(bookingId, updated);
    return updated;
  }

  /** EDIT-05: зміна водія та/або авто без зміни слота. */
  reassignBooking(bookingId: string, patch: BookingReassign): Booking {
    const booking = this.requireBooking(bookingId);
    if (booking.status !== 'booked') {
      throw problemError(
        409,
        ERROR_CODES.transitionNotAllowed,
        'Зміна водія та авто доступна лише до прибуття на місце',
      );
    }
    let updated: Booking = { ...booking, updatedAt: utcIso(this.now()) };

    if (patch.vehicle) {
      const store = this.requireStore(booking.storeId);
      const vehicle = this.normalizeVehicle(patch.vehicle);
      if (vehicle.weightTons > store.maxVehicleWeightTons) {
        throw problemError(
          422,
          ERROR_CODES.vehicleTooHeavy,
          `Ця філія приймає авто до ${store.maxVehicleWeightTons} т`,
          {
            maxVehicleWeightTons: store.maxVehicleWeightTons,
            actualWeightTons: vehicle.weightTons,
          },
        );
      }
      updated = {
        ...updated,
        vehicle: {
          plateNumber: vehicle.plateNumber,
          weightTons: vehicle.weightTons,
          brand: vehicle.brand ?? null,
        },
      };
    }

    if ('driverId' in patch) {
      updated = { ...updated, driverId: patch.driverId ?? null };
    }

    this.replaceBooking(bookingId, updated);
    return updated;
  }

  private requireBooking(bookingId: string): Booking {
    const booking = this.bookings.find((b) => b.id === bookingId);
    if (!booking) {
      throw problemError(
        404,
        ERROR_CODES.bookingNotFound,
        `Бронювання ${bookingId} не знайдено`,
      );
    }
    return booking;
  }

  private replaceBooking(bookingId: string, updated: Booking): void {
    this.bookings = this.bookings.map((b) => (b.id === bookingId ? updated : b));
  }

  // ── Маршрутні листи ─────────────────────────────────────────────────────

  private ensureSheetId(date: string): string {
    const existing = this.sheetIds.get(date);
    if (existing) {
      return existing;
    }
    const id = `rs-${date}`;
    this.sheetIds.set(date, id);
    return id;
  }

  /** RSHT-03: друкована форма листа на дату; склад — активні бронювання. */
  routeSheet(date: string): RouteSheet {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      throw problemError(
        422,
        ERROR_CODES.validationFailed,
        'Параметр «date» обовʼязковий і має бути у форматі YYYY-MM-DD',
      );
    }
    const points = this.sheetBookings(date).map((booking) =>
      this.toPoint(booking),
    );
    return {
      routeSheetId: this.ensureSheetId(date),
      supplierId: SUPPLIER_ID,
      supplierName: points.length > 0 ? SUPPLIER_NAME : null,
      date,
      printVersion: 1 + points.length,
      points,
    };
  }

  private sheetBookings(date: string): Booking[] {
    return this.bookings
      .filter(
        (b) =>
          b.type === 'scheduled' &&
          ACTIVE_STATUSES.has(b.status) &&
          b.localDate === date,
      )
      .sort((a, b) =>
        a.slotStart === b.slotStart
          ? a.rampId.localeCompare(b.rampId)
          : a.slotStart.localeCompare(b.slotStart),
      );
  }

  private toPoint(booking: Booking): RouteSheetPoint {
    return {
      bookingId: booking.id,
      city: booking.store.city,
      storeName: booking.store.displayName,
      address: booking.store.address,
      localTime: booking.localTime,
      slotStart: booking.slotStart,
      rampId: booking.rampId,
      orderId: booking.orderId,
      palletsCount: booking.palletsCount,
      plateNumber: booking.vehicle.plateNumber,
      driverId: booking.driverId,
      status: booking.status,
      // Як і бекенд: строк, до якого точку ще можна скасувати чи перенести.
      editableUntil: new Date(
        new Date(booking.slotStart).getTime() - MOCK_EDIT_DEADLINE_HOURS * 3_600_000,
      ).toISOString(),
      editDeadlineHours: MOCK_EDIT_DEADLINE_HOURS,
    };
  }

  /**
   * RSHT-02: водій на весь лист. `null` знімає водія з усіх точок листа —
   * симетрично до assignDriverToBooking().
   */
  assignDriverToSheet(
    date: string,
    driverId: string | null,
  ): RouteSheetAssignment {
    if (driverId) {
      this.requireActiveDriver(driverId);
    }
    for (const booking of this.sheetBookings(date)) {
      if (booking.driverId !== driverId) {
        this.replaceBooking(booking.id, { ...booking, driverId });
      }
    }
    return this.assignment(date);
  }

  assignDriverToBooking(
    bookingId: string,
    driverId: string | null,
  ): RouteSheetAssignment {
    const booking = this.requireBooking(bookingId);
    if (driverId) {
      this.requireActiveDriver(driverId);
    }
    this.replaceBooking(bookingId, { ...booking, driverId });
    return this.assignment(booking.localDate);
  }

  private assignment(date: string): RouteSheetAssignment {
    const bookings = this.sheetBookings(date);
    return {
      routeSheetId: this.ensureSheetId(date),
      supplierId: SUPPLIER_ID,
      date,
      entries: bookings.map((booking, index) => ({
        bookingId: booking.id,
        driverId: booking.driverId,
        sortOrder: index + 1,
      })),
      printVersion: 1 + bookings.length,
    };
  }

  private requireActiveDriver(driverId: string): Driver {
    const driver = this.drivers.find((d) => d.id === driverId && d.active);
    if (!driver) {
      throw problemError(
        404,
        ERROR_CODES.notFound,
        `Водія «${driverId}» не знайдено`,
      );
    }
    return driver;
  }

  // ── Довідник машин ──────────────────────────────────────────────────────

  listVehicles(includeInactive = true): Vehicle[] {
    return this.vehicles.filter((v) => includeInactive || v.active);
  }

  createVehicle(input: VehicleInput): Vehicle {
    const plateNumber = normalizePlate(input.plateNumber);
    if (this.vehicles.some((v) => v.plateNumber === plateNumber)) {
      throw problemError(
        409,
        ERROR_CODES.vehiclePlateDuplicate,
        'Авто з таким номером уже є у вашому довіднику.',
      );
    }
    if (validateWeightTons(input.weightTons)) {
      throw problemError(
        422,
        ERROR_CODES.validationFailed,
        'Вантажопідйомність має бути більшою за 0',
      );
    }
    const stamp = utcIso(this.now());
    const vehicle: Vehicle = {
      id: `veh-${this.sequence++}`,
      supplierId: SUPPLIER_ID,
      plateNumber,
      brand: input.brand?.trim() || null,
      weightTons: input.weightTons,
      active: true,
      lastUsedAt: null,
      createdAt: stamp,
      updatedAt: stamp,
    };
    this.vehicles = [...this.vehicles, vehicle];
    return vehicle;
  }

  updateVehicle(id: string, input: VehicleInput): Vehicle {
    const existing = this.requireVehicle(id);
    const plateNumber = normalizePlate(input.plateNumber);
    if (this.vehicles.some((v) => v.id !== id && v.plateNumber === plateNumber)) {
      throw problemError(
        409,
        ERROR_CODES.vehiclePlateDuplicate,
        'Авто з таким номером уже є у вашому довіднику.',
      );
    }
    const updated: Vehicle = {
      ...existing,
      plateNumber,
      brand: input.brand?.trim() || null,
      weightTons: input.weightTons,
      updatedAt: utcIso(this.now()),
    };
    this.vehicles = this.vehicles.map((v) => (v.id === id ? updated : v));
    return updated;
  }

  setVehicleActive(id: string, active: boolean): Vehicle {
    const existing = this.requireVehicle(id);
    const updated = { ...existing, active, updatedAt: utcIso(this.now()) };
    this.vehicles = this.vehicles.map((v) => (v.id === id ? updated : v));
    return updated;
  }

  /** SUP-VEH-04: видалення заборонене, якщо авто в активних бронюваннях. */
  removeVehicle(id: string): void {
    const vehicle = this.requireVehicle(id);
    const used = this.bookings.some(
      (b) =>
        ACTIVE_STATUSES.has(b.status) &&
        b.vehicle.plateNumber === vehicle.plateNumber,
    );
    if (used) {
      throw problemError(
        409,
        ERROR_CODES.vehicleHasActiveBookings,
        "Авто прив'язане до активних бронювань. Деактивуйте його замість видалення.",
      );
    }
    this.vehicles = this.vehicles.filter((v) => v.id !== id);
  }

  private requireVehicle(id: string): Vehicle {
    const vehicle = this.vehicles.find((v) => v.id === id);
    if (!vehicle) {
      throw problemError(404, ERROR_CODES.notFound, `Авто «${id}» не знайдено.`);
    }
    return vehicle;
  }

  // ── Водії ───────────────────────────────────────────────────────────────

  listDrivers(): Driver[] {
    return [...this.drivers];
  }

  createDriver(input: DriverInput): DriverCredentials {
    const phone = normalizePhone(input.phone);
    if (this.drivers.some((d) => d.phone === phone)) {
      throw problemError(
        409,
        ERROR_CODES.driverPhoneDuplicate,
        'Водій з таким телефоном уже зареєстрований.',
      );
    }
    if (input.defaultVehicleId) {
      this.requireVehicle(input.defaultVehicleId);
    }
    const stamp = utcIso(this.now());
    const driver: Driver = {
      id: `drv-${this.sequence++}`,
      accountId: `acc-${this.sequence++}`,
      supplierId: SUPPLIER_ID,
      phone,
      firstName: input.firstName.trim(),
      lastName: input.lastName.trim(),
      defaultVehicleId: input.defaultVehicleId ?? null,
      active: true,
      createdAt: stamp,
      updatedAt: stamp,
    };
    this.drivers = [...this.drivers, driver];
    return this.credentials(driver);
  }

  regenerateDriverPassword(id: string): DriverCredentials {
    return this.credentials(this.requireDriver(id));
  }

  setDriverActive(id: string, active: boolean): Driver {
    const driver = this.requireDriver(id);
    const updated = { ...driver, active, updatedAt: utcIso(this.now()) };
    this.drivers = this.drivers.map((d) => (d.id === id ? updated : d));
    if (!active) {
      // SUP-DRV-05: водій знімається з майбутніх маршрутних листів.
      const nowMs = this.now().getTime();
      this.bookings = this.bookings.map((b) =>
        b.driverId === id && new Date(b.slotStart).getTime() > nowMs
          ? { ...b, driverId: null }
          : b,
      );
    }
    return updated;
  }

  private requireDriver(id: string): Driver {
    const driver = this.drivers.find((d) => d.id === id);
    if (!driver) {
      throw problemError(
        404,
        ERROR_CODES.notFound,
        `Водія «${id}» не знайдено.`,
      );
    }
    return driver;
  }

  private credentials(driver: Driver): DriverCredentials {
    return {
      driver,
      login: driver.phone,
      password: this.generatePassword(),
      passwordNotice: 'Запишіть пароль — повторно він не показується.',
    };
  }

  private generatePassword(): string {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let out = '';
    for (let i = 0; i < 8; i++) {
      out += alphabet[Math.floor(Math.random() * alphabet.length)];
    }
    return out;
  }

  // ── Сідинг демо-даних ───────────────────────────────────────────────────

  private seedVehicles(): void {
    const stamp = utcIso(this.now());
    const base = {
      supplierId: SUPPLIER_ID,
      lastUsedAt: null,
      createdAt: stamp,
      updatedAt: stamp,
    };
    this.vehicles = [
      {
        id: 'veh-seed-1',
        plateNumber: 'АА1234ВС',
        brand: 'Renault Master',
        weightTons: 3.5,
        active: true,
        ...base,
      },
      {
        id: 'veh-seed-2',
        plateNumber: 'ВІ5678КМ',
        brand: 'MAN TGX',
        weightTons: 20,
        active: true,
        ...base,
      },
      {
        id: 'veh-seed-3',
        plateNumber: 'КА4321ТТ',
        brand: 'Mercedes Sprinter',
        weightTons: 5,
        active: true,
        ...base,
      },
      {
        id: 'veh-seed-4',
        plateNumber: 'АХ9087ЕМ',
        brand: 'DAF XF',
        weightTons: 40,
        active: false,
        ...base,
      },
    ];
  }

  private seedDrivers(): void {
    const stamp = utcIso(this.now());
    const base = {
      supplierId: SUPPLIER_ID,
      createdAt: stamp,
      updatedAt: stamp,
    };
    this.drivers = [
      {
        id: 'drv-seed-1',
        accountId: 'acc-drv-1',
        phone: '+380671112233',
        firstName: 'Петро',
        lastName: 'Коваленко',
        defaultVehicleId: 'veh-seed-1',
        active: true,
        ...base,
      },
      {
        id: 'drv-seed-2',
        accountId: 'acc-drv-2',
        phone: '+380502223344',
        firstName: 'Іван',
        lastName: 'Мельник',
        defaultVehicleId: 'veh-seed-2',
        active: true,
        ...base,
      },
      {
        id: 'drv-seed-3',
        accountId: 'acc-drv-3',
        phone: '+380931234567',
        firstName: 'Олег',
        lastName: 'Бондар',
        defaultVehicleId: null,
        active: false,
        ...base,
      },
    ];
  }

  /** Демо-бронювання на найближчі дні у реально доступних слотах. */
  private seedBookings(): void {
    const today = kyivDateIso(this.now());
    const candidates = this.visibleStores()
      .filter((store) => store.city === 'Київ')
      .slice(0, 12);
    const plan: Array<{ dayOffset: number; pallets: number; orderId?: string }> =
      [
        { dayOffset: 0, pallets: 12, orderId: 'ORD-100238' },
        { dayOffset: 0, pallets: 8 },
        { dayOffset: 1, pallets: 20, orderId: 'ORD-100241' },
        { dayOffset: 1, pallets: 33 },
        { dayOffset: 2, pallets: 6, orderId: 'ORD-100255' },
        { dayOffset: 3, pallets: 14 },
      ];

    const now = utcIso(this.now());
    // Пошук магазина ведеться заново для КОЖНОЇ точки плану: пізно ввечері
    // на сьогодні вільних слотів уже немає, і спільний курсор по магазинах
    // залишив би без демо-даних усі наступні дні.
    const used = new Set<string>();
    plan.forEach((item, i) => {
      const date = addDays(today, item.dayOffset);
      for (const store of candidates) {
        if (used.has(`${store.storeId}|${date}`)) {
          continue;
        }
        const grid = this.buildGrid(store, date);
        const slot = grid.slots.find((s) => s.selectable);
        if (!slot) {
          continue;
        }
        used.add(`${store.storeId}|${date}`);
        const vehicle = this.vehicles[i % 3];
        const driver = i % 2 === 0 ? this.drivers[0] : this.drivers[1];
        this.bookings = [
          ...this.bookings,
          {
            id: `bk-seed-${i + 1}`,
            type: 'scheduled',
            status: 'booked',
            storeId: store.storeId,
            store: {
              externalId: store.externalId,
              displayName: store.name,
              city: store.city,
              address: store.address,
            },
            rampId: slot.rampId,
            slotStart: slot.slotStart,
            slotEnd: slot.slotEnd,
            localDate: date,
            localTime: slot.localStart,
            supplierId: SUPPLIER_ID,
            supplierName: SUPPLIER_NAME,
            vehicle: {
              plateNumber: vehicle.plateNumber,
              weightTons: vehicle.weightTons,
              brand: vehicle.brand,
            },
            driverId: driver.id,
            orderId: item.orderId ?? null,
            palletsCount: item.pallets,
            delayed:
              i === 3
                ? {
                    flag: true,
                    reason: 'Затримка на попередній точці',
                    eta: null,
                  }
                : { flag: false, reason: null, eta: null },
            rescheduleOf: null,
            routeSheetId: this.ensureSheetId(date),
            createdAt: now,
            updatedAt: now,
          },
        ];
        break;
      }
    });
  }

  /** Довідкове: перелік магазинів (для тестів і діагностики). */
  allStores(): readonly MockStore[] {
    return this.stores;
  }

  /** Локальний час слота — використовується в підписах демо-даних. */
  slotLocalTime(slotStart: string): string {
    return kyivTimeHm(new Date(slotStart));
  }
}

export type { ApiProblemError };
