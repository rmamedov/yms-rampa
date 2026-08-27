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
  Booking,
  BookingStatus,
  BookingType,
  StatusChange,
} from '../models/booking.model';
import {
  WireAuthTokenResponse,
  WireBooking,
  WireStaffUser,
  WireStatusChange,
} from './wire.model';

export function toStatusChange(wire: WireStatusChange): StatusChange {
  return {
    from: (wire.from as BookingStatus | null) ?? null,
    to: wire.to as BookingStatus,
    at: wire.at,
    by: wire.by,
    meta: wire.meta ?? {},
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

/**
 * Магазини скоупу з профілю. Бекенд віддає лише ідентифікатори, тому описові
 * поля лишаються порожніми, доки їх не заповнить снапшот філії з бронювання.
 */
export function toStoreScopes(profile: StaffProfile): StoreScope[] {
  return profile.storeIds.map((storeId) => ({
    storeId,
    displayName: storeId,
    externalId: null,
    city: null,
    address: null,
  }));
}
