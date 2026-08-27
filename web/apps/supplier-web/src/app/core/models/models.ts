/**
 * Доменні моделі кабінету постачальника YMS «Рампа».
 * Усі часові мітки — UTC ISO 8601, відображення — Europe/Kyiv (SUP-UX-03).
 */

export type SupplierRole = 'supplier_admin' | 'supplier_operator';

export interface SupplierUser {
  readonly id: string;
  readonly email: string;
  readonly fullName: string;
  readonly role: SupplierRole;
  readonly supplierId: string;
  readonly supplierName: string;
}

export interface SupplierProfile {
  readonly id: string;
  readonly name: string;
  readonly taxCode: string;
  readonly address: string;
  readonly phone: string;
  readonly email: string;
}

export interface AuthTokens {
  readonly accessToken: string;
  readonly refreshToken: string;
  /** UTC ISO — момент закінчення access-токена. */
  readonly accessExpiresAt: string;
}

export interface AuthSession extends AuthTokens {
  readonly user: SupplierUser;
}

/** Місто з кількістю доступних постачальнику активних філій (SUP-CITY-02). */
export interface CityItem {
  readonly city: string;
  readonly storeCount: number;
}

export type StoreYmsStatus = 'active' | 'paused' | 'not_configured' | 'archived';

/** Картка філії у списку міста (SUP-BR-02). */
export interface BranchItem {
  readonly storeId: string;
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
  readonly maxVehicleWeightTons: number;
  readonly hasFreeSlots: boolean;
  readonly ymsStatus: StoreYmsStatus;
}

export interface BranchDetail extends BranchItem {
  readonly slotSizeMinutes: number;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly ramps: readonly Ramp[];
  readonly receivingWindows: readonly ReceivingWindow[];
}

export interface Ramp {
  readonly rampId: string;
  readonly name: string;
}

export interface ReceivingWindow {
  /** Локальний час Europe/Kyiv, «HH:mm». */
  readonly from: string;
  readonly to: string;
}

/** Канон станів слота (SLOT-03). */
export type SlotState =
  | 'available'
  | 'held'
  | 'booked'
  | 'reserved'
  | 'blocked'
  | 'past';

/** Канонічний ключ слота (SLOT-02): жодного окремого slotId не існує. */
export interface SlotKey {
  readonly storeId: string;
  readonly rampId: string;
  /** UTC ISO. */
  readonly slotStart: string;
}

export interface SlotCell {
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly state: SlotState;
  /** true для reserved-for-me та для власних бронювань (GRID-04). */
  readonly mine: boolean;
  readonly bookingId?: string;
}

export interface SlotRow {
  /** Локальний підпис рядка, «HH:mm». */
  readonly label: string;
  readonly slotStart: string;
  readonly cells: readonly SlotCell[];
}

/** Відповідь GET /stores/{storeId}/slots?date=YYYY-MM-DD (GRID-01, GRID-05). */
export interface SlotGrid {
  readonly storeId: string;
  /** Дата в Europe/Kyiv, YYYY-MM-DD. */
  readonly date: string;
  readonly ramps: readonly Ramp[];
  readonly rows: readonly SlotRow[];
  readonly maxVehicleWeightTons: number;
  readonly slotSizeMinutes: number;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  /** Серверний now (UTC) для коректних таймерів на клієнті. */
  readonly now: string;
}

export interface Vehicle {
  readonly id: string;
  readonly plateNumber: string;
  readonly brand?: string;
  readonly weightTons: number;
  readonly active: boolean;
}

export interface VehicleInput {
  readonly plateNumber: string;
  readonly brand?: string;
  readonly weightTons: number;
}

export interface Driver {
  readonly id: string;
  readonly phone: string;
  readonly firstName: string;
  readonly lastName: string;
  readonly vehicleId?: string;
  readonly plateNumber?: string;
  readonly active: boolean;
}

export interface DriverInput {
  readonly phone: string;
  readonly firstName: string;
  readonly lastName: string;
  readonly vehicleId?: string;
}

export interface DriverCreated {
  readonly driver: Driver;
  /** Показується рівно один раз (SUP-DRV-03). */
  readonly password: string;
  readonly smsSent: boolean;
}

export type BookingStatus =
  | 'booked'
  | 'arrived'
  | 'unloading'
  | 'completed'
  | 'cancelled'
  | 'no_show'
  | 'rejected';

export type BookingType = 'scheduled' | 'walk_in';

/** Знімок параметрів авто на момент бронювання (SUP-VEH-03). */
export interface BookingVehicleSnapshot {
  readonly vehicleId?: string;
  readonly plateNumber: string;
  readonly brand?: string;
  readonly weightTons: number;
}

export interface Booking {
  readonly id: string;
  readonly storeId: string;
  readonly storeExternalId: string;
  readonly city: string;
  readonly address: string;
  readonly rampId: string;
  readonly rampName: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly status: BookingStatus;
  readonly type: BookingType;
  readonly delayed: boolean;
  readonly delayReason?: string;
  readonly vehicle: BookingVehicleSnapshot;
  readonly orderId?: string;
  readonly palletsCount: number;
  readonly driverId?: string;
  readonly driverName?: string;
  readonly driverPhone?: string;
  readonly cancelReason?: string;
}

export interface CreateBookingRequest {
  readonly storeId: string;
  readonly rampId: string;
  readonly slotStart: string;
  readonly holdToken: string;
  readonly vehicleId?: string;
  readonly newVehicle?: VehicleInput;
  readonly orderId?: string;
  readonly palletsCount: number;
  /** Підтвердження перетину по авто (BOOK-04). */
  readonly confirmConflict?: boolean;
  /** Для перенесення (SUP-RS-03): id бронювання, яке буде скасоване атомарно. */
  readonly transferFromBookingId?: string;
}

/** Hold слота в Redis (HOLD-01, HOLD-02). */
export interface HoldSession {
  readonly holdToken: string;
  readonly storeId: string;
  readonly rampId: string;
  readonly slotStart: string;
  /** UTC ISO — поточний TTL. */
  readonly expiresAt: string;
  /** UTC ISO — межа holdMaxMinutes, далі продовження неможливе. */
  readonly maxUntil: string;
  /** Серверний now (UTC). */
  readonly now: string;
}

export interface RouteSheetSummary {
  /** Дата листа в Europe/Kyiv, YYYY-MM-DD. */
  readonly date: string;
  readonly pointsCount: number;
  readonly driverId?: string;
  readonly driverName?: string;
  readonly driverPhone?: string;
  readonly archived: boolean;
}

export interface RouteSheetDetail extends RouteSheetSummary {
  readonly points: readonly Booking[];
  readonly supplier: SupplierProfile;
}

export interface NetworkSettings {
  readonly holdTtlMinutes: number;
  readonly holdMaxMinutes: number;
  readonly maxActiveBookingsPerSupplier: number;
  readonly defaultVisibleDays: number;
}
