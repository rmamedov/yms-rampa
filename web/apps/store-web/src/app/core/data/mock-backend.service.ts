import { Injectable } from '@angular/core';
import {
  AuditEntry,
  Booking,
  BookingStatus,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
  WalkInPayload,
} from '../models/booking.model';
import {
  AuthTokens,
  LoginRequest,
  LoginResponse,
  StaffProfile,
} from '../models/auth.model';
import { AppError, ProblemDetails } from '../models/problem.model';
import { Slot, StoreConfig, SupplierRef } from '../models/store.model';
import { messageKeyForCode } from '../api/problem.util';
import {
  addDaysToDateKey,
  kyivToUtcIso,
  toKyivDateKey,
} from '../util/date.util';
import {
  MOCK_USERS,
  SUPPLIERS,
  buildStoreConfig,
  generateDay,
  slotStartsForDate,
} from '../fixtures/mock-data';
import { canStoreTransition } from '../util/booking-rules.util';

interface DayState {
  bookings: Booking[];
  audit: AuditEntry[];
}

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
 * In-memory реалізація бекенду store-web: дозволяє працювати з повноцінними
 * екранами без booking-service. Уся доменна логіка переходів продубльована
 * тут рівно так, як описано у розділі 9 SRS.
 */
@Injectable({ providedIn: 'root' })
export class MockBackend {
  /** Замінний годинник — потрібен для детермінованих тестів. */
  clock: () => string = () => new Date().toISOString();

  private readonly days = new Map<string, DayState>();
  private readonly configs = new Map<string, StoreConfig>();
  private currentUser: StaffProfile = MOCK_USERS[0];
  private pollCount = 0;

  // --- Автентифікація ---------------------------------------------------

  login(request: LoginRequest): LoginResponse {
    const email = request.email.trim().toLocaleLowerCase();
    if (!email || !request.password) {
      throw problem(401, 'AUTH_INVALID_CREDENTIALS', 'Невірний e-mail або пароль');
    }
    const known = MOCK_USERS.find((u) => u.email === email);
    const profile: StaffProfile = known ?? {
      ...MOCK_USERS[0],
      userId: `u-${email}`,
      email,
      fullName: 'Демо Користувач',
    };
    this.currentUser = profile;
    return { tokens: this.issueTokens(profile.userId), profile };
  }

  refresh(refreshToken: string): AuthTokens {
    if (!refreshToken.startsWith('mock-refresh.')) {
      throw problem(401, 'AUTH_TOKEN_INVALID', 'Сесія завершилась');
    }
    return this.issueTokens(refreshToken.slice('mock-refresh.'.length));
  }

  setCurrentUser(profile: StaffProfile): void {
    this.currentUser = profile;
  }

  private issueTokens(userId: string): AuthTokens {
    return {
      accessToken: `mock-access.${userId}.${Date.now()}`,
      refreshToken: `mock-refresh.${userId}`,
      expiresAt: Date.now() + 15 * 60_000,
    };
  }

  // --- Довідники --------------------------------------------------------

  getStoreConfig(storeId: string): StoreConfig {
    const cached = this.configs.get(storeId);
    if (cached) return cached;
    const scope =
      MOCK_USERS.flatMap((u) => u.stores).find((s) => s.storeId === storeId) ??
      null;
    if (!scope) {
      throw problem(403, 'STORE_FORBIDDEN', 'Немає доступу до цього магазину');
    }
    const config = buildStoreConfig(scope);
    this.configs.set(storeId, config);
    return config;
  }

  getSuppliers(): readonly SupplierRef[] {
    return SUPPLIERS;
  }

  // --- Дошка ------------------------------------------------------------

  getBoard(storeId: string, dateKey: string): {
    bookings: readonly Booking[];
    now: string;
  } {
    this.simulateRealtime(storeId, dateKey);
    return { bookings: [...this.day(storeId, dateKey).bookings], now: this.clock() };
  }

  getAuditLog(bookingId: string): readonly AuditEntry[] {
    for (const state of this.days.values()) {
      const entries = state.audit.filter((a) => a.bookingId === bookingId);
      if (entries.length) {
        return [...entries].sort((a, b) => a.at.localeCompare(b.at));
      }
    }
    return [];
  }

  /**
   * Обчислює сітку слотів дати з конфігурації + накладає бронювання
   * (спрощений GRID-01 для потреб магазину).
   */
  getSlots(storeId: string, dateKey: string): Slot[] {
    const config = this.getStoreConfig(storeId);
    const { bookings } = this.day(storeId, dateKey);
    const nowMs = new Date(this.clock()).getTime();
    const starts = slotStartsForDate(config, dateKey);
    const slots: Slot[] = [];

    for (const ramp of config.ramps) {
      for (const startMinutes of starts) {
        const slotStart = kyivToUtcIso(dateKey, startMinutes);
        const slotEnd = kyivToUtcIso(
          dateKey,
          startMinutes + config.slotSizeMinutes,
        );
        const booking = bookings.find(
          (b) =>
            b.rampId === ramp.rampId &&
            b.slotStart === slotStart &&
            b.status !== 'cancelled',
        );
        let state: Slot['state'] = 'available';
        if (booking) {
          state = 'booked';
        } else if (new Date(slotStart).getTime() < nowMs) {
          state = 'past';
        }
        slots.push({
          rampId: ramp.rampId,
          slotStart,
          slotEnd,
          state,
          bookingId: booking?.id ?? null,
        });
      }
    }
    return slots;
  }

  getWeek(storeId: string, mondayKey: string): { dateKey: string; slots: Slot[] }[] {
    return Array.from({ length: 7 }, (_, i) => {
      const dateKey = addDaysToDateKey(mondayKey, i);
      return { dateKey, slots: this.getSlots(storeId, dateKey) };
    });
  }

  /** Вільні слоти поточної дати для walk-in (STW-38). */
  freeSlotsNow(storeId: string): Slot[] {
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
  freeRampsForSlot(booking: Booking): string[] {
    const config = this.getStoreConfig(booking.storeId);
    const dateKey = toKyivDateKey(booking.slotStart);
    const { bookings } = this.day(booking.storeId, dateKey);
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

  // --- Дії --------------------------------------------------------------

  startUnloading(bookingId: string, version: number): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    this.assertTransition(booking, 'unloading');
    const at = this.clock();
    return this.commit(
      { ...booking, status: 'unloading', unloadingStartedAt: at },
      at,
      'status_changed',
      booking.status,
      'unloading',
    );
  }

  completeUnloading(
    bookingId: string,
    version: number,
    payload: CompleteUnloadingPayload,
  ): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    this.assertTransition(booking, 'completed');

    if (
      payload.unloadedPalletsCount < 0 ||
      payload.unloadedPalletsCount > booking.palletsCount
    ) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Кількість палет має бути від 0 до заявленої',
      );
    }
    const partial =
      payload.partialUnload || payload.unloadedPalletsCount < booking.palletsCount;
    if (partial && !payload.partialUnloadReason) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Оберіть причину часткового розвантаження',
      );
    }

    const at = this.clock();
    const updated: Booking = {
      ...booking,
      status: 'completed',
      completedAt: at,
      unloadedPalletsCount: payload.unloadedPalletsCount,
      partialUnload: partial
        ? {
            flag: true,
            reason: payload.partialUnloadReason ?? 'other',
            comment: payload.partialUnloadComment,
          }
        : null,
    };
    const result = this.commit(updated, at, 'status_changed', booking.status, 'completed');
    this.pushAudit(
      result,
      at,
      'unload_recorded',
      String(booking.palletsCount),
      String(payload.unloadedPalletsCount),
      payload.partialUnloadComment,
    );
    return result;
  }

  markNoShow(bookingId: string, version: number): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    this.assertTransition(booking, 'no_show');
    const nowMs = new Date(this.clock()).getTime();
    if (nowMs <= new Date(booking.slotEnd).getTime()) {
      throw problem(
        422,
        'NO_SHOW_TOO_EARLY',
        'Позначити «Не приїхав» можна лише після закінчення слоту',
      );
    }
    const at = this.clock();
    return this.commit(
      { ...booking, status: 'no_show' },
      at,
      'status_changed',
      booking.status,
      'no_show',
    );
  }

  reject(bookingId: string, version: number, payload: RejectPayload): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    this.assertTransition(booking, 'rejected');
    if (!payload.reason) {
      throw problem(
        422,
        'REJECT_REASON_REQUIRED',
        'Вкажіть причину відмови з довідника',
      );
    }
    if (payload.reason === 'other' && !payload.comment?.trim()) {
      throw problem(
        422,
        'REJECT_REASON_REQUIRED',
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
          by: this.currentUser.userId,
          reason: payload.reason,
          comment: payload.comment,
        },
      },
      at,
      'rejected',
      booking.status,
      'rejected',
      payload.comment,
    );
  }

  setDelay(bookingId: string, version: number, payload: DelayPayload): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    if (booking.status !== 'booked' && booking.status !== 'arrived') {
      throw problem(
        409,
        'BOOKING_STATUS_CONFLICT',
        'Статус уже змінено іншим користувачем',
        { currentStatus: booking.status },
      );
    }
    if (new Date(payload.eta).getTime() <= new Date(booking.slotStart).getTime()) {
      throw problem(
        422,
        'ETA_BEFORE_SLOT_START',
        'Новий очікуваний час має бути пізнішим за початок слоту',
      );
    }
    const at = this.clock();
    const wasDelayed = booking.delayed.flag;
    return this.commit(
      {
        ...booking,
        delayed: {
          flag: true,
          reason: payload.reason,
          eta: payload.eta,
          comment: payload.comment,
        },
      },
      at,
      wasDelayed ? 'delay_updated' : 'delay_set',
      booking.delayed.eta,
      payload.eta,
      payload.comment,
    );
  }

  clearDelay(bookingId: string, version: number): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    const at = this.clock();
    return this.commit(
      {
        ...booking,
        delayed: { flag: false, reason: null, eta: null, comment: null },
      },
      at,
      'delay_cleared',
      booking.delayed.eta,
      null,
    );
  }

  reassignRamp(
    bookingId: string,
    version: number,
    payload: ReassignPayload,
  ): Booking {
    const booking = this.requireBooking(bookingId);
    this.assertVersion(booking, version);
    if (booking.status !== 'booked' && booking.status !== 'arrived') {
      throw problem(
        409,
        'BOOKING_STATUS_CONFLICT',
        'Статус уже змінено іншим користувачем',
        { currentStatus: booking.status },
      );
    }
    if (!this.freeRampsForSlot(booking).includes(payload.rampId)) {
      throw problem(
        422,
        'RAMP_SLOT_TAKEN',
        'На обраній рампі немає вільного слота в цей час',
      );
    }
    const at = this.clock();
    const config = this.getStoreConfig(booking.storeId);
    const name = (id: string) =>
      config.ramps.find((r) => r.rampId === id)?.name ?? id;
    return this.commit(
      { ...booking, rampId: payload.rampId },
      at,
      'ramp_reassigned',
      name(booking.rampId),
      name(payload.rampId),
    );
  }

  createWalkIn(storeId: string, payload: WalkInPayload): Booking {
    const config = this.getStoreConfig(storeId);
    if (payload.weightTons > config.maxVehicleWeightTons) {
      throw problem(
        422,
        'VEHICLE_TOO_HEAVY',
        `Ця філія приймає авто до ${config.maxVehicleWeightTons} т`,
        { maxVehicleWeightTons: config.maxVehicleWeightTons },
      );
    }
    if (payload.palletsCount < 1 || payload.palletsCount > 33) {
      throw problem(422, 'VALIDATION_FAILED', 'Кількість палет — від 1 до 33');
    }
    if (!payload.supplierId && !payload.externalSupplierName?.trim()) {
      throw problem(
        422,
        'VALIDATION_FAILED',
        'Оберіть постачальника або вкажіть назву',
      );
    }

    const dateKey = toKyivDateKey(payload.slotStart);
    const state = this.day(storeId, dateKey);
    const taken = state.bookings.some(
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
      : (payload.externalSupplierName ?? '').trim();
    const slotEnd = new Date(
      new Date(payload.slotStart).getTime() + config.slotSizeMinutes * 60_000,
    ).toISOString();

    const booking: Booking = {
      id: `wi-${storeId.slice(0, 6)}-${Date.now()}`,
      type: 'walk_in',
      storeId,
      rampId: payload.rampId,
      slotStart: payload.slotStart,
      slotEnd,
      supplierId: payload.supplierId,
      supplierNameSnapshot: supplierName,
      vehicle: {
        plateNumber: payload.plateNumber.trim().toUpperCase(),
        weightTons: payload.weightTons,
        brand: null,
      },
      driver: null,
      orderId: payload.orderId?.trim() ? payload.orderId.trim() : null,
      palletsCount: payload.palletsCount,
      status: 'arrived',
      delayed: { flag: false, reason: null, eta: null, comment: null },
      arrivedAt: at,
      unloadingStartedAt: null,
      completedAt: null,
      cancelledAt: null,
      rejectedAt: null,
      unloadedPalletsCount: null,
      partialUnload: null,
      version: 1,
      updatedAt: at,
    };
    state.bookings.push(booking);
    this.pushAudit(booking, at, 'created', null, 'arrived', null);
    return booking;
  }

  // --- Внутрішнє --------------------------------------------------------

  private day(storeId: string, dateKey: string): DayState {
    const key = `${storeId}:${dateKey}`;
    let state = this.days.get(key);
    if (!state) {
      const config = this.getStoreConfig(storeId);
      const generated = generateDay(config, dateKey, this.clock());
      state = { bookings: generated.bookings, audit: generated.audit };
      this.days.set(key, state);
    }
    return state;
  }

  private requireBooking(bookingId: string): Booking {
    for (const state of this.days.values()) {
      const booking = state.bookings.find((b) => b.id === bookingId);
      if (booking) return booking;
    }
    throw problem(404, 'NOT_FOUND', 'Бронювання не знайдено');
  }

  private locate(bookingId: string): { state: DayState; index: number } {
    for (const state of this.days.values()) {
      const index = state.bookings.findIndex((b) => b.id === bookingId);
      if (index >= 0) return { state, index };
    }
    throw problem(404, 'NOT_FOUND', 'Бронювання не знайдено');
  }

  /** STW-17: захист від гонок двох операторів. */
  private assertVersion(booking: Booking, version: number): void {
    if (booking.version !== version) {
      throw problem(
        409,
        'BOOKING_STATUS_CONFLICT',
        'Статус уже змінено іншим користувачем',
        { currentStatus: booking.status, currentVersion: booking.version },
      );
    }
  }

  private assertTransition(booking: Booking, to: BookingStatus): void {
    if (!canStoreTransition(booking.status, to)) {
      throw problem(
        409,
        'BOOKING_STATUS_CONFLICT',
        'Статус уже змінено іншим користувачем',
        { currentStatus: booking.status },
      );
    }
  }

  private commit(
    updated: Booking,
    at: string,
    action: AuditEntry['action'],
    fromValue: string | null,
    toValue: string | null,
    comment: string | null = null,
  ): Booking {
    const { state, index } = this.locate(updated.id);
    const next: Booking = {
      ...updated,
      version: updated.version + 1,
      updatedAt: at,
    };
    state.bookings[index] = next;
    this.pushAudit(next, at, action, fromValue, toValue, comment);
    return next;
  }

  private pushAudit(
    booking: Booking,
    at: string,
    action: AuditEntry['action'],
    fromValue: string | null,
    toValue: string | null,
    comment: string | null = null,
  ): void {
    const dateKey = toKyivDateKey(booking.slotStart);
    const state = this.day(booking.storeId, dateKey);
    state.audit.push({
      id: `au-${booking.id}-${state.audit.length}`,
      bookingId: booking.id,
      at,
      actorKind: 'staff',
      actorName: this.currentUser.fullName,
      actorRole: this.currentUser.role,
      action,
      fromValue,
      toValue,
      comment,
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

    const state = this.day(storeId, dateKey);
    const nowMs = new Date(this.clock()).getTime();
    const index = state.bookings.findIndex(
      (b) =>
        b.status === 'booked' &&
        new Date(b.slotStart).getTime() > nowMs &&
        new Date(b.slotStart).getTime() - nowMs < 3 * 3600_000,
    );
    if (index < 0) return;
    const at = this.clock();
    const booking = state.bookings[index];
    state.bookings[index] = {
      ...booking,
      status: 'arrived',
      arrivedAt: at,
      version: booking.version + 1,
      updatedAt: at,
    };
    state.audit.push({
      id: `au-${booking.id}-rt`,
      bookingId: booking.id,
      at,
      actorKind: 'driver',
      actorName: booking.driver?.fullName ?? 'Водій',
      actorRole: null,
      action: 'status_changed',
      fromValue: 'booked',
      toValue: 'arrived',
      comment: null,
    });
  }
}
