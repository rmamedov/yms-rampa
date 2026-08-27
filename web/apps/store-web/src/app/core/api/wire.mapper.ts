/**
 * Єдине місце перетворення JSON бекенду на доменні моделі застосунку.
 * Використовується і HTTP-шлюзами, і мок-шлюзами — щоб мок не міг розійтися
 * з реальним контрактом непомітно.
 */

import {
  AuthTokens,
  LoginResponse,
  StaffProfile,
  StaffRole,
  StoreScope,
} from '../models/auth.model';
import {
  ActorContour,
  Booking,
  BookingStatus,
  BookingType,
  DriverRef,
  StatusChange,
} from '../models/booking.model';
import {
  Ramp,
  ReceivingWindow,
  Slot,
  SlotState,
  StoreConfig,
  SupplierRef,
} from '../models/store.model';
import {
  WireAuthTokenResponse,
  WireBooking,
  WireDriver,
  WireRamp,
  WireReceivingWindow,
  WireSlot,
  WireStaffUser,
  WireStatusChange,
  WireStoreBoard,
  WireStoreBrief,
  WireStoreConfig,
  WireSupplierRef,
  WireWeekDay,
} from './wire.model';

export function toStatusChange(wire: WireStatusChange): StatusChange {
  return {
    from: (wire.from as BookingStatus | null) ?? null,
    to: wire.to as BookingStatus,
    at: wire.at,
    by: wire.by,
    // Три поля виконавця приходять поруч із `by` і бувають порожніми: у записів,
    // зроблених до їх появи, ролі не збереглося. Порожнє лишається порожнім —
    // підставляти сюди ідентифікатор не можна, це й був дефект колонки «Хто».
    byRole: wire.byRole ?? null,
    byContour: (wire.byContour as ActorContour | undefined) ?? null,
    byLabel: wire.byLabel ?? null,
    meta: wire.meta ?? {},
  };
}

export function toDriver(wire: WireDriver | null | undefined): DriverRef | null {
  if (!wire) return null;
  return {
    driverId: wire.driverId,
    fullName: wire.fullName,
    phone: wire.phone ?? null,
    active: wire.active,
  };
}

export function toBooking(wire: WireBooking): Booking {
  return {
    id: wire.id,
    type: wire.type as BookingType,
    status: wire.status as BookingStatus,
    storeId: wire.storeId,
    store: wire.store,
    rampId: wire.rampId,
    slotStart: wire.slotStart,
    slotEnd: wire.slotEnd,
    localDate: wire.localDate,
    localTime: wire.localTime,
    supplierId: wire.supplierId,
    // Постачальник «поза системою» приходить лише як текстовий снапшот.
    supplierName: wire.supplierName ?? '',
    vehicle: wire.vehicle,
    driverId: wire.driverId,
    driver: toDriver(wire.driver),
    orderId: wire.orderId,
    palletsCount: wire.palletsCount,
    delayed: wire.delayed,
    arrivedAt: wire.arrivedAt,
    unloadingStartedAt: wire.unloadingStartedAt,
    completedAt: wire.completedAt,
    cancelledAt: wire.cancelledAt,
    cancellation: wire.cancellation,
    rejectedAt: wire.rejectedAt,
    unloadedPalletsCount: wire.unloadedPalletsCount,
    partialUnload: wire.partialUnload,
    rescheduleOf: wire.rescheduleOf,
    routeSheetId: wire.routeSheetId,
    createdBy: wire.createdBy,
    createdAt: wire.createdAt,
    updatedAt: wire.updatedAt,
    statusHistory: (wire.statusHistory ?? []).map(toStatusChange),
  };
}

export function toBookings(wire: readonly WireBooking[]): Booking[] {
  return wire.map(toBooking);
}

export function toStaffProfile(wire: WireStaffUser): StaffProfile {
  return {
    userId: wire.id,
    fullName: wire.fullName,
    email: wire.email,
    role: wire.role as StaffRole,
    roleLabel: wire.roleLabel,
    storeIds: [...wire.scope.storeIds],
    networkWide: wire.scope.networkWide,
    twoFactorEnabled: wire.twoFactorEnabled,
    permissions: [...wire.permissions],
  };
}

export function toAuthTokens(wire: WireAuthTokenResponse): AuthTokens {
  return {
    tokenType: wire.tokenType,
    accessToken: wire.accessToken,
    refreshToken: wire.refreshToken,
    sessionId: wire.sessionId,
    accessExpiresAt: wire.accessExpiresAt,
    refreshExpiresAt: wire.refreshExpiresAt,
    expiresAt: Date.parse(wire.accessExpiresAt),
  };
}

/** login і refresh повертають однакову плоску структуру. */
export function toLoginResponse(wire: WireAuthTokenResponse): LoginResponse {
  return { tokens: toAuthTokens(wire), profile: toStaffProfile(wire.user) };
}

// ---------------------------------------------------------------------------
// Читання контуру магазину
// ---------------------------------------------------------------------------

/**
 * Філія з GET /stores. Саме цей маршрут — джерело правди для перемикача:
 * він уже враховує права (магазинні ролі отримують свої філії, мережеві — всі
 * активні), тоді як `scope.storeIds` у профілі мережевої ролі порожній.
 */
export function toStoreScope(wire: WireStoreBrief): StoreScope {
  return {
    storeId: wire.storeId,
    displayName: wire.displayName,
    externalId: wire.externalId,
    city: wire.city,
    address: wire.address,
  };
}

/** Увесь перелік цілком: маршрут не пагінований і не обрізається клієнтом. */
export function toStoreScopes(wire: readonly WireStoreBrief[]): StoreScope[] {
  return wire.map(toStoreScope);
}

/**
 * Магазини скоупу з профілю — запасний варіант, доки GET /stores не відповів.
 * Бекенд у профілі віддає лише ідентифікатори, тому описові поля порожні.
 */
export function toProfileStoreScopes(profile: StaffProfile): StoreScope[] {
  return profile.storeIds.map((storeId) => ({
    storeId,
    displayName: storeId,
    externalId: null,
    city: null,
    address: null,
  }));
}

function toRamp(wire: WireRamp): Ramp {
  return { rampId: wire.rampId, name: wire.name, active: wire.active };
}

function toReceivingWindow(wire: WireReceivingWindow): ReceivingWindow {
  return {
    dayOfWeek: wire.dayOfWeek,
    intervals: (wire.intervals ?? []).map((interval) => ({
      from: interval.from,
      to: interval.to,
    })),
  };
}

export function toStoreConfig(wire: WireStoreConfig): StoreConfig {
  return {
    storeId: wire.storeId,
    externalId: wire.externalId,
    displayName: wire.displayName,
    city: wire.city,
    address: wire.address,
    ramps: (wire.ramps ?? []).map(toRamp),
    slotSizeMinutes: wire.slotSizeMinutes,
    receivingWindows: (wire.receivingWindows ?? []).map(toReceivingWindow),
    maxVehicleWeightTons: wire.maxVehicleWeightTons,
    noShowGraceMinutes: wire.noShowGraceMinutes,
    leadTimeMinutes: wire.leadTimeMinutes,
    horizonDays: wire.horizonDays,
  };
}

export function toSupplierRef(wire: WireSupplierRef): SupplierRef {
  return { supplierId: wire.supplierId, name: wire.name };
}

/** Довідник постачальників цілком — без зрізів і «першої сторінки». */
export function toSupplierRefs(
  wire: readonly WireSupplierRef[],
): SupplierRef[] {
  return wire.map(toSupplierRef);
}

export function toSlot(wire: WireSlot): Slot {
  return {
    rampId: wire.rampId,
    slotStart: wire.slotStart,
    slotEnd: wire.slotEnd,
    localStart: wire.localStart,
    state: wire.state as SlotState,
    selectable: wire.selectable,
    bookingId: wire.bookingId ?? null,
    // Обидва поля бекенд додає лише за наявності значення.
    reservedForSupplierId: wire.reservedForSupplierId ?? null,
    blockReason: wire.blockReason ?? null,
  };
}

export function toSlots(wire: readonly WireSlot[]): Slot[] {
  return wire.map(toSlot);
}

export function toWeekDaySlots(wire: WireWeekDay): {
  dateKey: string;
  slots: Slot[];
} {
  return { dateKey: wire.dateKey, slots: toSlots(wire.slots ?? []) };
}

/** Дошка прибуттів разом із серверним `now`. */
export function toBoardSnapshot(wire: WireStoreBoard): {
  bookings: Booking[];
  now: string;
} {
  return { bookings: toBookings(wire.bookings ?? []), now: wire.now };
}
