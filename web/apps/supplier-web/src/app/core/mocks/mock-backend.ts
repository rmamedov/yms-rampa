import { environment } from '../../../environments/environment';
import {
  ERROR_CODES,
  problemError,
  type ApiProblemError,
} from '../api/problem';
import type {
  AuthSession,
  AuthTokens,
  Booking,
  BranchDetail,
  BranchItem,
  CityItem,
  CreateBookingRequest,
  Driver,
  DriverCreated,
  DriverInput,
  HoldSession,
  NetworkSettings,
  Ramp,
  ReceivingWindow,
  RouteSheetDetail,
  RouteSheetSummary,
  SlotGrid,
  SlotKey,
  StoreYmsStatus,
  SupplierProfile,
  SupplierUser,
  Vehicle,
  VehicleInput,
} from '../models/models';
import {
  addDays,
  diffDays,
  kyivDateIso,
  kyivTimeHm,
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
  type SlotEngineStore,
} from './slot-engine';
import { RAW_BRANCH_ROWS } from './branches.fixture';

/** Магазин у мок-довіднику: конфігурація + видимість для постачальника. */
export interface MockStore extends SlotEngineStore {
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
  readonly ymsStatus: StoreYmsStatus;
  readonly allowedForSupplier: boolean;
  readonly blocks: readonly EngineBlock[];
  readonly reserves: readonly EngineReserve[];
}

interface MockHold {
  token: string;
  storeId: string;
  rampId: string;
  slotStart: string;
  expiresAt: number;
  maxUntil: number;
}

interface LoginAttempts {
  count: number;
  lockedUntil: number;
}

const ACTIVE_STATUSES = new Set(['booked', 'arrived', 'unloading']);

export const DEMO_CREDENTIALS = environment.demoLogin;

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

function buildStore(
  externalId: string,
  city: string,
  address: string,
  open: boolean,
  index: number,
): MockStore {
  const storeId = `st-${index}-${externalId}`;
  const h = hashString(storeId);
  const rampCount = 2 + (h % 4);
  const ramps: Ramp[] = Array.from({ length: rampCount }, (_, i) => ({
    rampId: `${storeId}-r${i + 1}`,
    name: String(i + 1),
  }));
  const windows = WINDOW_PRESETS[h % WINDOW_PRESETS.length];
  const ymsStatus: StoreYmsStatus = !open
    ? 'paused'
    : h % 29 === 0
      ? 'paused'
      : h % 31 === 0
        ? 'not_configured'
        : 'active';

  const blocks: EngineBlock[] =
    h % 11 === 0
      ? [{ rampId: null, from: '00:00', to: '23:59' }]
      : h % 7 === 0
        ? [{ rampId: ramps[1].rampId, from: '10:00', to: '12:00' }]
        : [];

  const reserves: EngineReserve[] =
    h % 5 === 0
      ? [
          {
            rampId: ramps[0].rampId,
            from: '08:00',
            to: '10:00',
            mine: true,
          },
        ]
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
    city,
    address,
    ymsStatus,
    allowedForSupplier: h % 13 !== 0,
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
 * In-memory реалізація бекенду YMS для роботи без сервера (environment.useMocks).
 * Методи синхронні й кидають ApiProblemError — це дозволяє покривати
 * бізнес-правила юніт-тестами без Angular та HTTP.
 */
export class MockBackend {
  readonly settings: NetworkSettings;
  private readonly stores: MockStore[];
  private readonly storeById = new Map<string, MockStore>();
  private readonly holds = new Map<string, MockHold>();
  private readonly holdsByToken = new Map<string, MockHold>();
  private readonly attempts = new Map<string, LoginAttempts>();
  private readonly sheetDrivers = new Map<string, string>();
  private vehicles: Vehicle[] = [];
  private drivers: Driver[] = [];
  private bookings: Booking[] = [];
  private sequence = 1;

  readonly user: SupplierUser = {
    id: 'usr-1',
    email: DEMO_CREDENTIALS.email,
    fullName: 'Олена Кравченко',
    role: 'supplier_admin',
    supplierId: 'sup-1',
    supplierName: 'ТОВ «Агро-Логістик»',
  };

  readonly profile: SupplierProfile = {
    id: 'sup-1',
    name: 'ТОВ «Агро-Логістик»',
    taxCode: '38294517',
    address: 'м. Київ, вул. Промислова, 12',
    phone: '+380442223344',
    email: 'office@agrologistic.ua',
  };

  constructor(
    private readonly clock: () => Date = () => new Date(),
    settings: Partial<NetworkSettings> = {},
  ) {
    this.settings = { ...DEFAULT_SETTINGS, ...settings };
    this.stores = RAW_BRANCH_ROWS.map((row, index) =>
      buildStore(row[0], row[1], row[2], row[5] === 1, index),
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
        ERROR_CODES.tooManyAttempts,
        'Забагато спроб. Спробуйте пізніше',
      );
    }
    if (/^\+?3?8?0\d{9}$/.test(key.replace(/[\s()-]/g, ''))) {
      throw problemError(
        403,
        ERROR_CODES.driverAccount,
        'Скористайтесь застосунком водія',
      );
    }
    if (key !== DEMO_CREDENTIALS.email || password !== DEMO_CREDENTIALS.password) {
      const next: LoginAttempts = {
        count: (attempt?.count ?? 0) + 1,
        lockedUntil: 0,
      };
      if (next.count >= 5) {
        next.lockedUntil = nowMs + 15 * 60000;
        next.count = 0;
        this.attempts.set(key, next);
        throw problemError(
          429,
          ERROR_CODES.tooManyAttempts,
          'Забагато спроб. Спробуйте пізніше',
        );
      }
      this.attempts.set(key, next);
      throw problemError(
        401,
        ERROR_CODES.invalidCredentials,
        'Невірний логін або пароль',
      );
    }
    this.attempts.delete(key);
    return { ...this.issueTokens(), user: this.user };
  }

  refresh(refreshToken: string): AuthTokens {
    if (!refreshToken.startsWith('mock-refresh')) {
      throw problemError(401, ERROR_CODES.unauthorized, 'Сесія завершилась');
    }
    return this.issueTokens();
  }

  private issueTokens(): AuthTokens {
    const now = this.now().getTime();
    return {
      accessToken: `mock-access.${now}`,
      refreshToken: `mock-refresh.${now}`,
      accessExpiresAt: new Date(now + 15 * 60000).toISOString(),
    };
  }

  // ── Довідник міст і філій ───────────────────────────────────────────────

  private visibleStores(): MockStore[] {
    return this.stores.filter(
      (store) => store.ymsStatus === 'active' && store.allowedForSupplier,
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
      .map((store) => ({
        storeId: store.storeId,
        externalId: store.externalId,
        city: store.city,
        address: store.address,
        maxVehicleWeightTons: store.maxVehicleWeightTons,
        ymsStatus: store.ymsStatus,
        hasFreeSlots: this.hasFreeSlotsWithinDays(store, 7),
      }));
  }

  branch(storeId: string): BranchDetail {
    const store = this.requireStore(storeId);
    return {
      storeId: store.storeId,
      externalId: store.externalId,
      city: store.city,
      address: store.address,
      maxVehicleWeightTons: store.maxVehicleWeightTons,
      ymsStatus: store.ymsStatus,
      hasFreeSlots: this.hasFreeSlotsWithinDays(store, 7),
      slotSizeMinutes: store.slotSizeMinutes,
      leadTimeMinutes: store.leadTimeMinutes,
      bookingHorizonDays: store.bookingHorizonDays,
      ramps: store.ramps,
      receivingWindows: store.windows,
    };
  }

  private requireStore(storeId: string): MockStore {
    const store = this.storeById.get(storeId);
    if (!store || store.ymsStatus !== 'active' || !store.allowedForSupplier) {
      throw problemError(
        403,
        ERROR_CODES.supplierNotAllowed,
        'Ця філія недоступна вашому підприємству',
      );
    }
    return store;
  }

  private hasFreeSlotsWithinDays(store: MockStore, days: number): boolean {
    const today = kyivDateIso(this.now());
    for (let i = 0; i < days; i++) {
      const grid = this.buildGrid(store, addDays(today, i));
      if (grid.rows.some((row) => row.cells.some((c) => c.state === 'available'))) {
        return true;
      }
    }
    return false;
  }

  // ── Сітка слотів ────────────────────────────────────────────────────────

  slots(storeId: string, date: string): SlotGrid {
    const store = this.requireStore(storeId);
    const today = kyivDateIso(this.now());
    const distance = diffDays(today, date);
    if (distance < 0 || distance > store.bookingHorizonDays) {
      throw problemError(
        422,
        ERROR_CODES.dateOutOfHorizon,
        `Бронювання доступне не далі ніж на ${store.bookingHorizonDays} днів вперед`,
        { days: store.bookingHorizonDays },
      );
    }
    return this.buildGrid(store, date);
  }

  private buildGrid(store: MockStore, date: string): SlotGrid {
    const now = this.now();
    const mine: EngineBooking[] = this.bookings
      .filter((b) => b.storeId === store.storeId && ACTIVE_STATUSES.has(b.status))
      .map((b) => ({
        id: b.id,
        rampId: b.rampId,
        slotStart: b.slotStart,
        mine: true,
      }));
    const holds = [...this.holds.values()]
      .filter((hold) => hold.storeId === store.storeId)
      .map((hold) => ({
        rampId: hold.rampId,
        slotStart: hold.slotStart,
        expiresAt: new Date(hold.expiresAt).toISOString(),
      }));

    const grid = buildSlotGrid({
      store,
      date,
      now,
      bookings: mine,
      holds,
      blocks: store.blocks,
      reserves: store.reserves,
    });

    // Бронювання інших постачальників — детерміновані, ~30% вільних слотів.
    return {
      ...grid,
      rows: grid.rows.map((row) => ({
        ...row,
        cells: row.cells.map((cell) =>
          cell.state === 'available' &&
          !cell.mine &&
          this.isForeignBooked(store.storeId, cell.rampId, cell.slotStart)
            ? { ...cell, state: 'booked' as const, mine: false }
            : cell,
        ),
      })),
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
      );
    }
    const nowMs = this.now().getTime();
    const hold: MockHold = {
      token: `hold-${this.sequence++}`,
      storeId: key.storeId,
      rampId: key.rampId,
      slotStart: key.slotStart,
      expiresAt: nowMs + this.settings.holdTtlMinutes * 60000,
      maxUntil: nowMs + this.settings.holdMaxMinutes * 60000,
    };
    this.holds.set(mapKey, hold);
    this.holdsByToken.set(hold.token, hold);
    return this.toHoldSession(hold);
  }

  heartbeat(token: string): HoldSession {
    this.purgeHolds();
    const hold = this.holdsByToken.get(token);
    if (!hold) {
      throw problemError(
        409,
        ERROR_CODES.holdExpired,
        'Час на оформлення вийшов. Оберіть слот ще раз',
      );
    }
    const now = this.now();
    hold.expiresAt = extendedExpiry(
      now,
      new Date(hold.maxUntil),
      this.settings.holdTtlMinutes,
    ).getTime();
    return this.toHoldSession(hold);
  }

  release(token: string): void {
    const hold = this.holdsByToken.get(token);
    if (!hold) {
      return;
    }
    this.holdsByToken.delete(token);
    this.holds.delete(
      this.slotKey({
        storeId: hold.storeId,
        rampId: hold.rampId,
        slotStart: hold.slotStart,
      }),
    );
  }

  private toHoldSession(hold: MockHold): HoldSession {
    return {
      holdToken: hold.token,
      storeId: hold.storeId,
      rampId: hold.rampId,
      slotStart: hold.slotStart,
      expiresAt: new Date(hold.expiresAt).toISOString(),
      maxUntil: new Date(hold.maxUntil).toISOString(),
      now: this.now().toISOString(),
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
    return (
      own || this.isForeignBooked(store.storeId, key.rampId, key.slotStart)
    );
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
    this.purgeHolds();
    const store = this.requireStore(request.storeId);
    const hold = this.holdsByToken.get(request.holdToken);
    if (
      !hold ||
      hold.storeId !== request.storeId ||
      hold.rampId !== request.rampId ||
      hold.slotStart !== request.slotStart
    ) {
      throw problemError(
        409,
        ERROR_CODES.holdExpired,
        'Час на оформлення вийшов. Оберіть слот ще раз',
      );
    }

    const palletsError = validatePallets(request.palletsCount);
    if (palletsError) {
      throw problemError(
        422,
        ERROR_CODES.palletsOutOfRange,
        'Вкажіть від 1 до 33 палет',
      );
    }

    const vehicle = this.resolveVehicle(request);
    if (vehicle.weightTons > store.maxVehicleWeightTons) {
      throw problemError(
        422,
        ERROR_CODES.vehicleTooHeavy,
        `Ця філія приймає авто до ${store.maxVehicleWeightTons} т`,
        { tons: store.maxVehicleWeightTons },
      );
    }

    const now = this.now();
    const slotStartMs = new Date(request.slotStart).getTime();
    const distance = diffDays(kyivDateIso(now), kyivDateIso(new Date(slotStartMs)));
    if (distance > store.bookingHorizonDays) {
      throw problemError(
        422,
        ERROR_CODES.dateOutOfHorizon,
        `Бронювання доступне не далі ніж на ${store.bookingHorizonDays} днів вперед`,
        { days: store.bookingHorizonDays },
      );
    }
    if (slotStartMs < now.getTime() + store.leadTimeMinutes * 60000) {
      throw problemError(
        422,
        ERROR_CODES.slotInPast,
        'Цей слот уже недоступний для бронювання',
      );
    }

    const transferred = request.transferFromBookingId
      ? this.bookings.find((b) => b.id === request.transferFromBookingId)
      : undefined;
    const limit = this.settings.maxActiveBookingsPerSupplier;
    const activeCount =
      this.activeFutureBookings().length - (transferred ? 1 : 0);
    if (activeCount >= limit) {
      throw problemError(
        422,
        ERROR_CODES.bookingLimitExceeded,
        `Досягнуто ліміт активних бронювань (${limit}). Скасуйте неактуальні бронювання або зверніться до адміністратора мережі`,
        { limit },
      );
    }

    if (
      this.isSlotTaken(store, {
        storeId: request.storeId,
        rampId: request.rampId,
        slotStart: request.slotStart,
      })
    ) {
      this.release(request.holdToken);
      throw problemError(
        409,
        ERROR_CODES.slotAlreadyBooked,
        'Слот щойно забронював інший постачальник',
      );
    }

    if (!request.confirmConflict) {
      const conflict = this.findVehicleTimeConflict(
        vehicle.plateNumber,
        request.slotStart,
        store.slotSizeMinutes,
        request.transferFromBookingId,
      );
      if (conflict) {
        throw problemError(
          409,
          ERROR_CODES.vehicleTimeConflict,
          'Це авто вже має бронювання, що перетинається в часі',
          { bookingId: conflict.id },
        );
      }
    }

    const ramp = store.ramps.find((r) => r.rampId === request.rampId);
    const booking: Booking = {
      id: `bk-${this.sequence++}`,
      storeId: store.storeId,
      storeExternalId: store.externalId,
      city: store.city,
      address: store.address,
      rampId: request.rampId,
      rampName: ramp?.name ?? '?',
      slotStart: request.slotStart,
      slotEnd: new Date(
        slotStartMs + store.slotSizeMinutes * 60000,
      ).toISOString(),
      status: 'booked',
      type: 'scheduled',
      delayed: false,
      vehicle: {
        vehicleId: vehicle.id,
        plateNumber: vehicle.plateNumber,
        brand: vehicle.brand,
        weightTons: vehicle.weightTons,
      },
      orderId: request.orderId?.trim() || undefined,
      palletsCount: request.palletsCount,
      driverId: transferred?.driverId,
      driverName: transferred?.driverName,
      driverPhone: transferred?.driverPhone,
    };

    if (transferred) {
      // SUP-RS-03: нове бронювання створюється атомарно зі скасуванням старого.
      this.replaceBooking(transferred.id, {
        ...transferred,
        status: 'cancelled',
        cancelReason: 'Перенесено на інший слот',
      });
    }
    this.bookings = [...this.bookings, booking];
    this.release(request.holdToken);
    return booking;
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

  private resolveVehicle(request: CreateBookingRequest): Vehicle {
    if (request.vehicleId) {
      const existing = this.vehicles.find((v) => v.id === request.vehicleId);
      if (!existing) {
        throw problemError(422, ERROR_CODES.unknown, 'Авто не знайдено');
      }
      return existing;
    }
    if (!request.newVehicle) {
      throw problemError(422, ERROR_CODES.unknown, 'Оберіть авто або додайте нове');
    }
    return this.createVehicle(request.newVehicle);
  }

  upcoming(limit: number): Booking[] {
    const nowMs = this.now().getTime();
    return this.bookings
      .filter(
        (b) =>
          ACTIVE_STATUSES.has(b.status) &&
          new Date(b.slotEnd).getTime() >= nowMs,
      )
      .sort((a, b) => a.slotStart.localeCompare(b.slotStart))
      .slice(0, limit);
  }

  cancelBooking(bookingId: string, reason?: string): Booking {
    const booking = this.requireBooking(bookingId);
    if (!ACTIVE_STATUSES.has(booking.status)) {
      throw problemError(
        422,
        ERROR_CODES.unknown,
        'Бронювання вже не можна скасувати',
      );
    }
    const updated: Booking = {
      ...booking,
      status: 'cancelled',
      cancelReason: reason?.trim() || undefined,
    };
    this.replaceBooking(bookingId, updated);
    return updated;
  }

  assignDriverToBooking(bookingId: string, driverId: string | null): Booking {
    const booking = this.requireBooking(bookingId);
    if (booking.status !== 'booked') {
      throw problemError(
        422,
        ERROR_CODES.unknown,
        'Зміна водія доступна лише до прибуття на місце',
      );
    }
    const driver = driverId
      ? this.drivers.find((d) => d.id === driverId && d.active)
      : undefined;
    const updated: Booking = {
      ...booking,
      driverId: driver?.id,
      driverName: driver ? `${driver.lastName} ${driver.firstName}` : undefined,
      driverPhone: driver?.phone,
    };
    this.replaceBooking(bookingId, updated);
    return updated;
  }

  /**
   * SUP-RS-07: заміна авто в бронюванні без зміни слота — до статусу arrived,
   * з повторною перевіркою тоннажу проти maxVehicleWeightTons філії.
   */
  changeBookingVehicle(bookingId: string, vehicleId: string): Booking {
    const booking = this.requireBooking(bookingId);
    if (booking.status !== 'booked') {
      throw problemError(
        422,
        ERROR_CODES.unknown,
        'Заміна авто доступна лише до прибуття на місце',
      );
    }
    const vehicle = this.vehicles.find((v) => v.id === vehicleId);
    if (!vehicle) {
      throw problemError(404, ERROR_CODES.unknown, 'Авто не знайдено');
    }
    const store = this.requireStore(booking.storeId);
    if (vehicle.weightTons > store.maxVehicleWeightTons) {
      throw problemError(
        422,
        ERROR_CODES.vehicleTooHeavy,
        `Ця філія приймає авто до ${store.maxVehicleWeightTons} т`,
        { tons: store.maxVehicleWeightTons },
      );
    }
    const updated: Booking = {
      ...booking,
      vehicle: {
        vehicleId: vehicle.id,
        plateNumber: vehicle.plateNumber,
        brand: vehicle.brand,
        weightTons: vehicle.weightTons,
      },
    };
    this.replaceBooking(bookingId, updated);
    return updated;
  }

  private requireBooking(bookingId: string): Booking {
    const booking = this.bookings.find((b) => b.id === bookingId);
    if (!booking) {
      throw problemError(404, ERROR_CODES.unknown, 'Бронювання не знайдено');
    }
    return booking;
  }

  private replaceBooking(bookingId: string, updated: Booking): void {
    this.bookings = this.bookings.map((b) => (b.id === bookingId ? updated : b));
  }

  // ── Маршрутні листи ─────────────────────────────────────────────────────

  routeSheets(): RouteSheetSummary[] {
    const today = kyivDateIso(this.now());
    const byDate = new Map<string, Booking[]>();
    for (const booking of this.bookings) {
      if (booking.status === 'cancelled') {
        continue;
      }
      const date = kyivDateIso(new Date(booking.slotStart));
      byDate.set(date, [...(byDate.get(date) ?? []), booking]);
    }
    return [...byDate.entries()]
      .map(([date, points]) => this.summary(date, points, today))
      .sort((a, b) => b.date.localeCompare(a.date));
  }

  private summary(
    date: string,
    points: Booking[],
    today: string,
  ): RouteSheetSummary {
    const driverId = this.resolveSheetDriver(date, points);
    const driver = this.drivers.find((d) => d.id === driverId);
    return {
      date,
      pointsCount: points.length,
      driverId: driver?.id,
      driverName: driver ? `${driver.lastName} ${driver.firstName}` : undefined,
      driverPhone: driver?.phone,
      archived: diffDays(today, date) < 0,
    };
  }

  private resolveSheetDriver(
    date: string,
    points: Booking[],
  ): string | undefined {
    const assigned = this.sheetDrivers.get(date);
    if (assigned) {
      return assigned;
    }
    const ids = new Set(points.map((p) => p.driverId).filter(Boolean));
    return ids.size === 1 ? ([...ids][0] as string) : undefined;
  }

  routeSheet(date: string): RouteSheetDetail {
    const today = kyivDateIso(this.now());
    const points = this.bookings
      .filter(
        (b) =>
          b.status !== 'cancelled' &&
          kyivDateIso(new Date(b.slotStart)) === date,
      )
      .sort((a, b) => a.slotStart.localeCompare(b.slotStart));
    return {
      ...this.summary(date, points, today),
      points,
      supplier: this.profile,
    };
  }

  assignDriverToSheet(date: string, driverId: string | null): RouteSheetDetail {
    if (driverId) {
      const driver = this.drivers.find((d) => d.id === driverId && d.active);
      if (!driver) {
        throw problemError(422, ERROR_CODES.unknown, 'Водій недоступний');
      }
      this.sheetDrivers.set(date, driverId);
    } else {
      this.sheetDrivers.delete(date);
    }
    for (const booking of [...this.bookings]) {
      if (
        booking.status === 'booked' &&
        kyivDateIso(new Date(booking.slotStart)) === date
      ) {
        this.assignDriverToBooking(booking.id, driverId);
      }
    }
    return this.routeSheet(date);
  }

  // ── Довідник машин ──────────────────────────────────────────────────────

  listVehicles(): Vehicle[] {
    return [...this.vehicles];
  }

  createVehicle(input: VehicleInput): Vehicle {
    const plateNumber = normalizePlate(input.plateNumber);
    if (this.vehicles.some((v) => v.plateNumber === plateNumber)) {
      throw problemError(
        409,
        ERROR_CODES.duplicatePlate,
        'Авто з таким номером уже є у вашому довіднику',
      );
    }
    const weightError = validateWeightTons(input.weightTons);
    if (weightError) {
      throw problemError(
        422,
        ERROR_CODES.unknown,
        'Вантажопідйомність має бути більшою за 0',
      );
    }
    const vehicle: Vehicle = {
      id: `veh-${this.sequence++}`,
      plateNumber,
      brand: input.brand?.trim() || undefined,
      weightTons: input.weightTons,
      active: true,
    };
    this.vehicles = [...this.vehicles, vehicle];
    return vehicle;
  }

  updateVehicle(id: string, input: VehicleInput): Vehicle {
    const existing = this.vehicles.find((v) => v.id === id);
    if (!existing) {
      throw problemError(404, ERROR_CODES.unknown, 'Авто не знайдено');
    }
    const plateNumber = normalizePlate(input.plateNumber);
    if (
      this.vehicles.some((v) => v.id !== id && v.plateNumber === plateNumber)
    ) {
      throw problemError(
        409,
        ERROR_CODES.duplicatePlate,
        'Авто з таким номером уже є у вашому довіднику',
      );
    }
    const updated: Vehicle = {
      ...existing,
      plateNumber,
      brand: input.brand?.trim() || undefined,
      weightTons: input.weightTons,
    };
    this.vehicles = this.vehicles.map((v) => (v.id === id ? updated : v));
    return updated;
  }

  setVehicleActive(id: string, active: boolean): Vehicle {
    const existing = this.vehicles.find((v) => v.id === id);
    if (!existing) {
      throw problemError(404, ERROR_CODES.unknown, 'Авто не знайдено');
    }
    const updated = { ...existing, active };
    this.vehicles = this.vehicles.map((v) => (v.id === id ? updated : v));
    return updated;
  }

  /** SUP-VEH-04: видалення заборонене, якщо авто в активних бронюваннях. */
  removeVehicle(id: string): void {
    const used = this.bookings.some(
      (b) => ACTIVE_STATUSES.has(b.status) && b.vehicle.vehicleId === id,
    );
    if (used) {
      throw problemError(
        409,
        ERROR_CODES.vehicleInUse,
        'Авто привʼязане до активних бронювань — доступна лише деактивація',
      );
    }
    this.vehicles = this.vehicles.filter((v) => v.id !== id);
  }

  // ── Водії ───────────────────────────────────────────────────────────────

  listDrivers(): Driver[] {
    return [...this.drivers];
  }

  createDriver(input: DriverInput): DriverCreated {
    const phone = normalizePhone(input.phone);
    if (this.drivers.some((d) => d.phone === phone)) {
      throw problemError(
        409,
        ERROR_CODES.driverPhoneTaken,
        'Водій з таким телефоном уже зареєстрований',
      );
    }
    const vehicle = input.vehicleId
      ? this.vehicles.find((v) => v.id === input.vehicleId)
      : undefined;
    const driver: Driver = {
      id: `drv-${this.sequence++}`,
      phone,
      firstName: input.firstName.trim(),
      lastName: input.lastName.trim(),
      vehicleId: vehicle?.id,
      plateNumber: vehicle?.plateNumber,
      active: true,
    };
    this.drivers = [...this.drivers, driver];
    return { driver, password: this.generatePassword(), smsSent: true };
  }

  regenerateDriverPassword(id: string): DriverCreated {
    const driver = this.drivers.find((d) => d.id === id);
    if (!driver) {
      throw problemError(404, ERROR_CODES.unknown, 'Водія не знайдено');
    }
    return { driver, password: this.generatePassword(), smsSent: true };
  }

  setDriverActive(id: string, active: boolean): Driver {
    const driver = this.drivers.find((d) => d.id === id);
    if (!driver) {
      throw problemError(404, ERROR_CODES.unknown, 'Водія не знайдено');
    }
    const updated = { ...driver, active };
    this.drivers = this.drivers.map((d) => (d.id === id ? updated : d));
    if (!active) {
      // SUP-DRV-05: водій знімається з майбутніх маршрутних листів.
      const nowMs = this.now().getTime();
      this.bookings = this.bookings.map((b) =>
        b.driverId === id && new Date(b.slotStart).getTime() > nowMs
          ? { ...b, driverId: undefined, driverName: undefined, driverPhone: undefined }
          : b,
      );
      for (const [date, assigned] of [...this.sheetDrivers.entries()]) {
        if (assigned === id) {
          this.sheetDrivers.delete(date);
        }
      }
    }
    return updated;
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
    this.vehicles = [
      {
        id: 'veh-seed-1',
        plateNumber: 'АА1234ВС',
        brand: 'Renault Master',
        weightTons: 3.5,
        active: true,
      },
      {
        id: 'veh-seed-2',
        plateNumber: 'ВІ5678КМ',
        brand: 'MAN TGX',
        weightTons: 20,
        active: true,
      },
      {
        id: 'veh-seed-3',
        plateNumber: 'КА4321ТТ',
        brand: 'Mercedes Sprinter',
        weightTons: 5,
        active: true,
      },
      {
        id: 'veh-seed-4',
        plateNumber: 'АХ9087ЕМ',
        brand: 'DAF XF',
        weightTons: 40,
        active: false,
      },
    ];
  }

  private seedDrivers(): void {
    this.drivers = [
      {
        id: 'drv-seed-1',
        phone: '+380671112233',
        firstName: 'Петро',
        lastName: 'Коваленко',
        vehicleId: 'veh-seed-1',
        plateNumber: 'АА1234ВС',
        active: true,
      },
      {
        id: 'drv-seed-2',
        phone: '+380502223344',
        firstName: 'Іван',
        lastName: 'Мельник',
        vehicleId: 'veh-seed-2',
        plateNumber: 'ВІ5678КМ',
        active: true,
      },
      {
        id: 'drv-seed-3',
        phone: '+380931234567',
        firstName: 'Олег',
        lastName: 'Бондар',
        active: false,
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

    let storeIndex = 0;
    plan.forEach((item, i) => {
      const date = addDays(today, item.dayOffset);
      while (storeIndex < candidates.length) {
        const store = candidates[storeIndex++];
        const grid = this.buildGrid(store, date);
        const row = grid.rows.find((r) =>
          r.cells.some((c) => c.state === 'available' && !c.mine),
        );
        const cell = row?.cells.find((c) => c.state === 'available' && !c.mine);
        if (!row || !cell) {
          continue;
        }
        const ramp = store.ramps.find((r) => r.rampId === cell.rampId);
        const vehicle = this.vehicles[i % 3];
        const driver = i % 2 === 0 ? this.drivers[0] : this.drivers[1];
        this.bookings = [
          ...this.bookings,
          {
            id: `bk-seed-${i + 1}`,
            storeId: store.storeId,
            storeExternalId: store.externalId,
            city: store.city,
            address: store.address,
            rampId: cell.rampId,
            rampName: ramp?.name ?? '1',
            slotStart: cell.slotStart,
            slotEnd: cell.slotEnd,
            status: 'booked',
            type: 'scheduled',
            delayed: i === 3,
            delayReason: i === 3 ? 'Затримка на попередній точці' : undefined,
            vehicle: {
              vehicleId: vehicle.id,
              plateNumber: vehicle.plateNumber,
              brand: vehicle.brand,
              weightTons: vehicle.weightTons,
            },
            orderId: item.orderId,
            palletsCount: item.pallets,
            driverId: driver.id,
            driverName: `${driver.lastName} ${driver.firstName}`,
            driverPhone: driver.phone,
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
