import { Injectable } from '@angular/core';
import { messageKeyForCode } from '../api/problem.util';
import {
  WireAuthTokenResponse,
  WireBooking,
  WireCompleteRequest,
  WireDelayRequest,
  WireLoginRequest,
  WireReassignRequest,
  WireRejectRequest,
  WireSlot,
  WireStaffUser,
  WireStatusChange,
  WireStoreBoard,
  WireStoreBrief,
  WireWalkInRequest,
  WireWeekDay,
} from '../api/wire.model';
import { NETWORK_WIDE_ROLES, StaffRole } from '../models/auth.model';
import { REASON_OTHER } from '../models/booking.model';
import { AppError, ProblemDetails } from '../models/problem.model';
import { SlotState, StoreConfig, SupplierRef } from '../models/store.model';
import {
  addDaysToDateKey,
  formatTime,
  kyivToUtcIso,
  toKyivDateKey,
} from '../util/date.util';
import {
  ACTOR_LABELS,
  MOCK_STORES,
  MOCK_USERS,
  MockStore,
  SUPPLIERS,
  buildStoreConfig,
  contourOfRole,
  findMockStore,
  generateDay,
  slotStartsForDate,
} from '../fixtures/mock-data';

/** Машина станів бекенду (`Booking::ALLOWED_TRANSITIONS`). */
const ALLOWED_TRANSITIONS: Readonly<Record<string, readonly string[]>> = {
  booked: ['arrived', 'cancelled', 'no_show'],
  arrived: ['unloading', 'rejected'],
  unloading: ['completed'],
  completed: [],
  cancelled: [],
  no_show: [],
  rejected: [],
};

const REJECT_REASONS = new Set([
  'перевищення тоннажу',
  'невідповідність вантажу',
  'відсутні документи',
  REASON_OTHER,
]);
const PARTIAL_REASONS = new Set([
  'немає місця',
  'бій/брак',
  'розбіжність із замовленням',
  'відмова частини вантажу',
  REASON_OTHER,
]);
const DELAY_REASONS = new Set([
  'затори',
  'поломка',
  'затримка на попередній точці',
  REASON_OTHER,
]);

function problem(
  status: number,
  code: string,
  detail: string,
  extra: Record<string, unknown> = {},
): AppError {
  const body: ProblemDetails = {
    type: `https://yms.rampa/problems/${code.toLowerCase()}`,
    title: code,
    status,
    detail,
    code,
    ...extra,
  };
  return new AppError(body, messageKeyForCode(code));
}

/**
 * In-memory реалізація контуру магазину: дозволяє працювати з повноцінними
 * екранами без booking-service.
 *
 * Мок дзеркалить РЕАЛЬНИЙ бекенд: віддає ті самі JSON-структури
 * (`api/wire.model.ts`), застосовує ті самі правила переходів
 * (`Booking::ALLOWED_TRANSITIONS`), приймає ті самі значення довідників
 * (україномовні backed-enum'и) і кидає ті самі коди помилок.
 *
 * Читання (`getStores`, `getStoreConfig`, `getSuppliers`, `getBoard`,
 * `getSlots`, `getWeek`) повторює `App\Controller\Store\StoreReadController`:
 * колекції — плоскі масиви без пагінації, дошка — обʼєкт із серверним `now`.
 */
@Injectable({ providedIn: 'root' })
export class MockBackend {
  /** Замінний годинник — потрібен для детермінованих тестів. */
  clock: () => string = () => new Date().toISOString();

  private readonly days = new Map<string, WireBooking[]>();
  private readonly configs = new Map<string, StoreConfig>();
  private currentUser: WireStaffUser = MOCK_USERS[0];
  private pollCount = 0;

  // --- Автентифікація ---------------------------------------------------

  login(request: WireLoginRequest): WireAuthTokenResponse {
    const email = (request.email ?? '').trim().toLocaleLowerCase();
    if (!email || !request.password) {
      throw problem(401, 'AUTH_INVALID_CREDENTIALS', 'Невірний логін або пароль.');
    }
    const known = MOCK_USERS.find((u) => u.email === email);
    const user: WireStaffUser = known ?? {
      ...MOCK_USERS[0],
      id: `u-${email}`,
      email,
      fullName: 'Демо Користувач',
    };
    this.currentUser = user;
    return this.tokenResponse(user);
  }

  refresh(refreshToken: string): WireAuthTokenResponse {
    if (!refreshToken.startsWith('mock-refresh.')) {
      throw problem(401, 'AUTH_TOKEN_INVALID', 'Помилка автентифікації. Увійдіть повторно.');
    }
    const userId = refreshToken.slice('mock-refresh.'.length);
    const user = MOCK_USERS.find((u) => u.id === userId) ?? this.currentUser;
    this.currentUser = user;
    return this.tokenResponse(user);
  }

  setCurrentUser(user: WireStaffUser): void {
    this.currentUser = user;
  }

  private tokenResponse(user: WireStaffUser): WireAuthTokenResponse {
    const now = new Date(this.clock()).getTime();
    const accessExpiresAt = new Date(now + 15 * 60_000);
    const refreshExpiresAt = new Date(now + 30 * 24 * 3600_000);
    return {
      tokenType: 'Bearer',
      accessToken: `mock-access.${user.id}.${now}`,
      expiresIn: 900,
      accessExpiresAt: accessExpiresAt.toISOString(),
      refreshToken: `mock-refresh.${user.id}`,
      refreshExpiresAt: refreshExpiresAt.toISOString(),
      sessionId: `mock-session.${user.id}`,
      user,
    };
  }

  // --- Довідники --------------------------------------------------------

  /**
   * GET /stores очима бекенду: перелік уже враховує права. Мережеві ролі
   * (RBAC-16) отримують усі активні філії — саме тому їхній порожній
   * `scope.storeIds` перемикачу не потрібен; магазинні — рівно свій скоуп,
   * і порожній скоуп означає нуль магазинів, а не «всі» (RBAC-13).
   */
  getStores(): readonly WireStoreBrief[] {
    const user = this.currentUser;
    const role = user.role as StaffRole;
    const networkWide =
      user.scope.networkWide || NETWORK_WIDE_ROLES.includes(role);
    const visible = networkWide
      ? MOCK_STORES
      : MOCK_STORES.filter((store) => user.scope.storeIds.includes(store.storeId));
    return visible.map((store) => ({
      storeId: store.storeId,
      externalId: store.externalId,
      displayName: store.displayName,
      city: store.city,
      address: store.address,
      ymsStatus: 'active',
    }));
  }

  getStoreConfig(storeId: string): StoreConfig {
    const cached = this.configs.get(storeId);
    if (cached) return cached;
    const config = buildStoreConfig(this.requireStore(storeId));
    this.configs.set(storeId, config);
    return config;
  }

  /** Довідник постачальників філії — цілком, без пагінації (як і бекенд). */
  getSuppliers(): readonly SupplierRef[] {
    return SUPPLIERS;
  }

  // --- Дошка ------------------------------------------------------------

  getBoard(storeId: string, dateKey: string): WireStoreBoard {
    this.simulateRealtime(storeId, dateKey);
    return {
      storeId,
      date: dateKey,
      now: this.clock(),
      bookings: [...this.day(storeId, dateKey)],
    };
  }

  /**
   * Обчислює сітку слотів дати з конфігурації + накладає бронювання
   * (спрощений GRID-01 для потреб магазину). Форма відповіді — рівно
   * `StaffSlotPresenter::slots()`: слот плюс `bookingId`.
   */
  getSlots(storeId: string, dateKey: string): WireSlot[] {
    const config = this.getStoreConfig(storeId);
    const bookings = this.day(storeId, dateKey);
    const nowMs = new Date(this.clock()).getTime();
    const starts = slotStartsForDate(config, dateKey);
    const slots: WireSlot[] = [];

    for (const ramp of config.ramps) {
      for (const startMinutes of starts) {
        const slotStart = kyivToUtcIso(dateKey, startMinutes);
        const slotEnd = kyivToUtcIso(
          dateKey,
          startMinutes + config.slotSizeMinutes,
        );
        // Ключ слота звільняється разом зі скасуванням (EDIT-03).
        const booking = bookings.find(
          (b) =>
            b.rampId === ramp.rampId &&
            b.slotStart === slotStart &&
            b.status !== 'cancelled',
        );
        let state: SlotState = 'available';
        if (booking) {
          state = 'booked';
        } else if (new Date(slotStart).getTime() < nowMs) {
          state = 'past';
        }
        slots.push({
          rampId: ramp.rampId,
          slotStart,
          slotEnd,
          localStart: formatTime(slotStart),
          state,
          selectable: state === 'available',
          bookingId: booking?.id ?? null,
        });
      }
    }
    return slots;
  }

  getWeek(storeId: string, mondayKey: string): WireWeekDay[] {
    return Array.from({ length: 7 }, (_, i) => {
      const dateKey = addDaysToDateKey(mondayKey, i);
      return { dateKey, slots: this.getSlots(storeId, dateKey) };
    });
  }

  /** Вільні слоти поточної дати для walk-in (STW-38). */
  freeSlotsNow(storeId: string): WireSlot[] {
    const nowIso = this.clock();
    const dateKey = toKyivDateKey(nowIso);
    const nowMs = new Date(nowIso).getTime();
    return this.getSlots(storeId, dateKey).filter(
      (s) =>
        s.state === 'available' &&
        new Date(s.slotEnd).getTime() > nowMs - 30 * 60_000,
    );
  }

  /** Рампи, у яких слот бронювання вільний (STW-41/42). */
  freeRampsForSlot(booking: WireBooking): string[] {
    const config = this.getStoreConfig(booking.storeId);
    const dateKey = toKyivDateKey(booking.slotStart);
    const bookings = this.day(booking.storeId, dateKey);
    return config.ramps
      .filter((ramp) => ramp.rampId !== booking.rampId && ramp.active)
      .filter(
        (ramp) =>
          !bookings.some(
            (b) =>
              b.rampId === ramp.rampId &&
              b.slotStart === booking.slotStart &&
              b.status !== 'cancelled' &&
              b.status !== 'no_show' &&
              b.status !== 'rejected',
          ),
      )
      .map((ramp) => ramp.rampId);
  }

  // --- Дії (ST-01..ST-07, DLY-01, EDIT-06) ------------------------------

  /** ST-01: booked → arrived. */
  markArrived(bookingId: string): WireBooking {
    const booking = this.requireBooking(bookingId);
    const at = this.clock();
    return this.commit(
      { ...booking, status: 'arrived', arrivedAt: at },
      booking.status,
      'arrived',
      at,
    );
  }

  /** ST-02: arrived → unloading; перехід знімає прапорець затримки. */
  startUnloading(bookingId: string): WireBooking {
    const booking = this.requireBooking(bookingId);
    const at = this.clock();
    return this.commit(
      {
        ...booking,
        status: 'unloading',
        unloadingStartedAt: at,
        delayed: { flag: false, reason: null, eta: null },
      },
      booking.status,
      'unloading',
      at,
    );
  }

  /** ST-03: unloading → completed. */
  completeUnloading(bookingId: string, payload: WireCompleteRequest): WireBooking {
    const booking = this.requireBooking(bookingId);
    const unloaded = payload.unloadedPalletsCount ?? booking.palletsCount;

    if (unloaded < 0 || unloaded > booking.palletsCount) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        `Розвантажено палет має бути в діапазоні 0..${booking.palletsCount} (заявлено ${booking.palletsCount})`,
      );
    }
    if (unloaded < booking.palletsCount && !payload.partialUnload) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Часткове розвантаження потребує причини з довідника',
      );
    }
    if (payload.partialUnload) {
      this.assertEnum(PARTIAL_REASONS, payload.partialUnload.reason);
      if (
        payload.partialUnload.reason === REASON_OTHER &&
        !payload.partialUnload.comment
      ) {
        throw problem(422, 'VALIDATION_FAILED', 'Для причини «інше» потрібен коментар');
      }
    }

    const at = this.clock();
    return this.commit(
      {
        ...booking,
        status: 'completed',
        completedAt: at,
        unloadedPalletsCount: unloaded,
        partialUnload:
          unloaded < booking.palletsCount && payload.partialUnload
            ? {
                flag: true,
                reason: payload.partialUnload.reason,
                comment: payload.partialUnload.comment ?? null,
              }
            : null,
      },
      booking.status,
      'completed',
      at,
      { unloadedPalletsCount: unloaded },
    );
  }

  /** NOSH-02: ручний no_show лише після slotEnd. */
  markNoShow(bookingId: string): WireBooking {
    const booking = this.requireBooking(bookingId);
    const at = this.clock();
    if (new Date(at).getTime() < new Date(booking.slotEnd).getTime()) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Ручна позначка «не приїхав» можлива лише після завершення слоту',
      );
    }
    return this.commit(
      { ...booking, status: 'no_show' },
      booking.status,
      'no_show',
      at,
      { auto: false },
    );
  }

  /** ST-07: arrived → rejected. */
  reject(bookingId: string, payload: WireRejectRequest): WireBooking {
    const booking = this.requireBooking(bookingId);
    this.assertEnum(REJECT_REASONS, payload.reason);
    if (payload.reason === REASON_OTHER && !payload.comment?.trim()) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Для причини «інше» коментар обовʼязковий',
      );
    }
    const at = this.clock();
    return this.commit(
      {
        ...booking,
        status: 'rejected',
        rejectedAt: {
          at,
          by: this.currentUser.id,
          reason: payload.reason,
          comment: payload.comment ?? null,
        },
      },
      booking.status,
      'rejected',
      at,
      { reason: payload.reason },
    );
  }

  /** DLY-01: прапорець затримки; статус не змінюється. */
  setDelay(bookingId: string, payload: WireDelayRequest): WireBooking {
    const booking = this.requireBooking(bookingId);
    this.assertEnum(DELAY_REASONS, payload.reason);
    if (booking.status !== 'booked' && booking.status !== 'arrived') {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Затримку можна позначити лише для бронювання у статусі «booked» або «arrived»',
      );
    }
    const at = this.clock();
    if (new Date(payload.eta).getTime() <= new Date(at).getTime()) {
      throw problem(422, 'VALIDATION_FAILED', 'ETA має бути в майбутньому');
    }
    if (payload.reason === REASON_OTHER && !payload.comment?.trim()) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Для причини «інше» коментар обовʼязковий',
      );
    }

    // Бекенд склеює причину з коментарем для «інше» — окремого поля немає.
    const reason =
      payload.reason === REASON_OTHER
        ? `${payload.reason}: ${(payload.comment ?? '').trim()}`
        : payload.reason;

    return this.replace(
      {
        ...booking,
        delayed: { flag: true, reason, eta: payload.eta },
        updatedAt: at,
      },
    );
  }

  /** EDIT-06: переведення на іншу вільну рампу того самого слота. */
  reassignRamp(bookingId: string, payload: WireReassignRequest): WireBooking {
    const booking = this.requireBooking(bookingId);
    if (!this.freeRampsForSlot(booking).includes(payload.rampId)) {
      throw problem(
        409,
        'SLOT_ALREADY_BOOKED',
        'Слот уже зайнятий — оберіть інший вільний слот або рампу',
      );
    }
    const at = this.clock();
    return this.replace({ ...booking, rampId: payload.rampId, updatedAt: at });
  }

  /** WALK-01/WALK-04: бронювання створюється одразу у статусі arrived. */
  createWalkIn(payload: WireWalkInRequest): WireBooking {
    const store = this.requireStore(payload.storeId);
    const config = this.getStoreConfig(payload.storeId);

    if (payload.vehicle.weightTons > config.maxVehicleWeightTons) {
      throw problem(
        422,
        'VEHICLE_TOO_HEAVY',
        `Ця філія приймає авто до ${config.maxVehicleWeightTons} т`,
        { maxVehicleWeightTons: config.maxVehicleWeightTons },
      );
    }
    if (payload.palletsCount < 1 || payload.palletsCount > 33) {
      throw problem(422, 'PALLETS_OUT_OF_RANGE', 'Кількість палет — від 1 до 33');
    }
    if (!payload.supplierId && !payload.supplierName?.trim()) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Оберіть постачальника або вкажіть назву',
      );
    }

    const dateKey = toKyivDateKey(payload.slotStart);
    const bookings = this.day(payload.storeId, dateKey);
    const taken = bookings.some(
      (b) =>
        b.rampId === payload.rampId &&
        b.slotStart === payload.slotStart &&
        b.status !== 'cancelled' &&
        b.status !== 'no_show' &&
        b.status !== 'rejected',
    );
    if (taken) {
      throw problem(
        409,
        'SLOT_ALREADY_BOOKED',
        'Слот уже зайнятий — оберіть інший вільний слот або рампу',
      );
    }

    const at = this.clock();
    const supplierName = payload.supplierId
      ? (SUPPLIERS.find((s) => s.supplierId === payload.supplierId)?.name ??
        'Постачальник')
      : (payload.supplierName ?? '').trim();
    const slotEnd = new Date(
      new Date(payload.slotStart).getTime() + config.slotSizeMinutes * 60_000,
    ).toISOString();

    const booking: WireBooking = {
      id: `wi-${store.externalId}-${Date.now()}`,
      type: 'walk_in',
      status: 'arrived',
      storeId: store.storeId,
      store: {
        externalId: store.externalId,
        displayName: store.displayName,
        city: store.city,
        address: store.address,
      },
      rampId: payload.rampId,
      slotStart: payload.slotStart,
      slotEnd,
      localDate: dateKey,
      localTime: new Intl.DateTimeFormat('uk-UA', {
        timeZone: 'Europe/Kyiv',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
      }).format(new Date(payload.slotStart)),
      supplierId: payload.supplierId,
      supplierName,
      vehicle: {
        plateNumber: payload.vehicle.plateNumber.trim().toUpperCase(),
        weightTons: payload.vehicle.weightTons,
        brand: payload.vehicle.brand ?? null,
      },
      driverId: null,
      driver: null,
      orderId: payload.orderId?.trim() ? payload.orderId.trim() : null,
      palletsCount: payload.palletsCount,
      delayed: { flag: false, reason: null, eta: null },
      arrivedAt: at,
      unloadingStartedAt: null,
      completedAt: null,
      cancelledAt: null,
      cancellation: null,
      rejectedAt: null,
      unloadedPalletsCount: null,
      partialUnload: null,
      rescheduleOf: null,
      routeSheetId: null,
      createdBy: this.currentUser.id,
      createdAt: at,
      updatedAt: at,
      statusHistory: [
        {
          from: null,
          to: 'arrived',
          at,
          ...this.actorOfCurrentUser(),
          meta: { walkIn: true },
        },
      ],
    };
    bookings.push(booking);
    return booking;
  }

  // --- Внутрішнє --------------------------------------------------------

  private requireStore(storeId: string): MockStore {
    const store = findMockStore(storeId);
    if (!store) {
      throw problem(403, 'ACCESS_DENIED', 'Немає доступу до цього магазину');
    }
    return store;
  }

  private day(storeId: string, dateKey: string): WireBooking[] {
    const key = `${storeId}:${dateKey}`;
    let bookings = this.days.get(key);
    if (!bookings) {
      const store = this.requireStore(storeId);
      bookings = generateDay(
        store,
        this.getStoreConfig(storeId),
        dateKey,
        this.clock(),
      );
      this.days.set(key, bookings);
    }
    return bookings;
  }

  private requireBooking(bookingId: string): WireBooking {
    for (const bookings of this.days.values()) {
      const booking = bookings.find((b) => b.id === bookingId);
      if (booking) return booking;
    }
    throw problem(404, 'BOOKING_NOT_FOUND', 'Бронювання не знайдено');
  }

  /**
   * Виконавець запису журналу: ідентифікатор ПЛЮС роль, контур і людиночитана
   * позначка — рівно те, що бекенд кладе в `StatusChange::toArray()`.
   */
  private actorOfCurrentUser(): Pick<
    WireStatusChange,
    'by' | 'byRole' | 'byContour' | 'byLabel'
  > {
    const role = this.currentUser.role as StaffRole;
    return {
      by: this.currentUser.id,
      byRole: role,
      byContour: contourOfRole(role),
      byLabel: ACTOR_LABELS[role],
    };
  }

  private assertEnum(allowed: ReadonlySet<string>, value: string): void {
    if (!allowed.has(value)) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        `Значення «${value}» відсутнє в довіднику. Допустимі: ${[...allowed].join(', ')}`,
      );
    }
  }

  /** Записує оновлене бронювання назад у сховище дня. */
  private replace(updated: WireBooking): WireBooking {
    for (const bookings of this.days.values()) {
      const index = bookings.findIndex((b) => b.id === updated.id);
      if (index >= 0) {
        bookings[index] = updated;
        return updated;
      }
    }
    throw problem(404, 'BOOKING_NOT_FOUND', 'Бронювання не знайдено');
  }

  /** Перехід статусу з перевіркою машини станів і записом у statusHistory. */
  private commit(
    updated: WireBooking,
    from: string,
    to: string,
    at: string,
    meta?: Record<string, unknown>,
  ): WireBooking {
    if (!ALLOWED_TRANSITIONS[from].includes(to)) {
      throw problem(
        409,
        'INVALID_STATUS_TRANSITION',
        `Перехід зі статусу «${from}» у «${to}» неможливий`,
        { from, to },
      );
    }
    const entry: WireStatusChange = {
      from,
      to,
      at,
      ...this.actorOfCurrentUser(),
      ...(meta ? { meta } : {}),
    };
    return this.replace({
      ...updated,
      updatedAt: at,
      statusHistory: [...updated.statusHistory, entry],
    });
  }

  /**
   * Демонстрація realtime: на третьому полінгу найближче booked-бронювання
   * поточної дати «натискає На місці» — картка підсвічується як arrived.
   */
  private simulateRealtime(storeId: string, dateKey: string): void {
    this.pollCount += 1;
    if (this.pollCount !== 3) return;
    if (dateKey !== toKyivDateKey(this.clock())) return;

    const bookings = this.day(storeId, dateKey);
    const nowMs = new Date(this.clock()).getTime();
    const index = bookings.findIndex(
      (b) =>
        b.status === 'booked' &&
        new Date(b.slotStart).getTime() > nowMs &&
        new Date(b.slotStart).getTime() - nowMs < 3 * 3600_000,
    );
    if (index < 0) return;
    const at = this.clock();
    const booking = bookings[index];
    bookings[index] = {
      ...booking,
      status: 'arrived',
      arrivedAt: at,
      updatedAt: at,
      statusHistory: [
        ...booking.statusHistory,
        // Прибуття відмічає сам водій — партнерський контур.
        {
          from: 'booked',
          to: 'arrived',
          at,
          by: booking.driverId ?? 'driver',
          byRole: 'driver',
          byContour: 'partner',
          byLabel: ACTOR_LABELS.driver,
        },
      ],
    };
  }
}
