/**
 * Сирі DTO бекенду `/api/supplier/v1/...` і перетворення їх у доменні моделі.
 *
 * Тут зібрано всі «шви» контракту: конверти `{items, total}`, поля з іншими
 * іменами (`defaultVehicleId`, `maxExpiresAt`, `_id`) і значення, які бекенд
 * віддає як `null` для неналаштованих магазинів.
 */

import type {
  AuthSession,
  Booking,
  BranchItem,
  CityItem,
  Driver,
  DriverCredentials,
  HoldSession,
  Ramp,
  RouteSheet,
  RouteSheetAssignment,
  Slot,
  SlotGrid,
  Vehicle,
} from '../models/models';

export interface ListEnvelope<T> {
  readonly items: readonly T[];
  readonly total?: number;
  readonly page?: number;
  readonly perPage?: number;
}

export interface CityDto {
  readonly city: string;
  readonly storeCount: number;
}

export interface RampDto {
  readonly rampId: string;
  readonly number: number;
  readonly name: string;
}

export interface StoreDto {
  readonly storeId: string;
  readonly externalId: string;
  readonly name: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: number | null;
  readonly longitude: number | null;
  readonly phone: string | null;
  readonly ramps: readonly RampDto[] | null;
  readonly maxVehicleWeightTons: number | null;
  readonly slotSizeMinutes: number | null;
  readonly leadTimeMinutes: number | null;
  readonly bookingHorizonDays: number | null;
}

export interface SlotDto {
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly localStart: string;
  readonly state: Slot['state'];
  readonly selectable: boolean;
  readonly reservedForYou?: boolean;
  readonly reservedForSupplierId?: string;
  readonly blockReason?: string;
}

export interface SlotGridDto {
  readonly storeId: string;
  readonly date: string;
  readonly maxVehicleWeightTons: number;
  readonly slotSizeMinutes: number;
  readonly leadTimeMinutes: number;
  readonly now: string;
  readonly slots: readonly SlotDto[];
}

export interface HoldDto {
  readonly holdToken: string;
  readonly storeId: string;
  readonly rampId: string;
  readonly slotStart: string;
  readonly expiresAt: string;
  readonly maxExpiresAt: string;
  readonly secondsLeft: number;
}

export interface VehicleDto {
  readonly id: string;
  readonly supplierId: string;
  readonly plateNumber: string;
  readonly brand: string | null;
  readonly weightTons: number;
  readonly active: boolean;
  readonly lastUsedAt: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

export interface DriverDto {
  readonly id: string;
  readonly accountId: string;
  readonly supplierId: string;
  readonly phone: string;
  readonly firstName: string;
  readonly lastName: string;
  readonly defaultVehicleId: string | null;
  readonly active: boolean;
  readonly createdAt: string;
  readonly updatedAt: string;
}

/** Створення водія: поля водія + одноразові облікові дані в одному об'єкті. */
export interface DriverCredentialsDto extends DriverDto {
  readonly login: string;
  readonly password: string;
  readonly passwordNotice: string;
}

export interface BookingDto {
  readonly id: string;
  readonly type: Booking['type'];
  readonly status: Booking['status'];
  readonly storeId: string;
  readonly store: Booking['store'];
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly localDate: string;
  readonly localTime: string;
  readonly supplierId: string | null;
  readonly supplierName: string | null;
  readonly vehicle: Booking['vehicle'];
  readonly driverId: string | null;
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly delayed: Booking['delayed'];
  readonly rescheduleOf: string | null;
  readonly routeSheetId: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

export interface RouteSheetDto {
  readonly routeSheetId: string;
  readonly supplierId: string;
  readonly supplierName: string | null;
  readonly date: string;
  readonly printVersion: number;
  readonly points: RouteSheet['points'];
}

/** RouteSheet::toArray — ідентифікатор приходить як `_id`. */
export interface RouteSheetAssignmentDto {
  readonly _id: string;
  readonly supplierId: string;
  readonly date: string;
  readonly entries: RouteSheetAssignment['entries'];
  readonly printVersion: number;
}

export interface AuthProfileDto {
  readonly accountId: string;
  readonly login: string;
  readonly role: string;
  readonly contour: string;
  readonly supplierId: string;
  readonly driverId: string | null;
  readonly mustChangePassword: boolean;
}

export interface AuthResultDto {
  readonly accessToken: string;
  readonly accessExpiresAt: string;
  readonly expiresIn: number;
  readonly refreshToken: string;
  readonly refreshExpiresAt: string;
  readonly tokenType: string;
  readonly profile: AuthProfileDto;
}

// ── Перетворення ────────────────────────────────────────────────────────────

export function itemsOf<T>(envelope: ListEnvelope<T> | null | undefined): T[] {
  return envelope?.items ? [...envelope.items] : [];
}

export function toCity(dto: CityDto): CityItem {
  return { city: dto.city, storeCount: dto.storeCount };
}

export function toRamp(dto: RampDto): Ramp {
  return { rampId: dto.rampId, number: dto.number, name: dto.name };
}

/**
 * Магазин без активної конфігурації бекенд віддає з `null` у параметрах;
 * у вибірку постачальника такі не потрапляють (eligibleOnly), але поля
 * лишаються nullable — нормалізуємо, щоб UI не рахував NaN.
 */
export function toBranch(dto: StoreDto): BranchItem {
  return {
    storeId: dto.storeId,
    externalId: dto.externalId,
    name: dto.name,
    city: dto.city,
    address: dto.address,
    latitude: dto.latitude ?? null,
    longitude: dto.longitude ?? null,
    phone: dto.phone ?? null,
    ramps: (dto.ramps ?? []).map(toRamp),
    maxVehicleWeightTons: dto.maxVehicleWeightTons ?? 0,
    slotSizeMinutes: dto.slotSizeMinutes ?? 0,
    leadTimeMinutes: dto.leadTimeMinutes ?? 0,
    bookingHorizonDays: dto.bookingHorizonDays ?? 0,
  };
}

export function toSlotGrid(dto: SlotGridDto): SlotGrid {
  return {
    storeId: dto.storeId,
    date: dto.date,
    maxVehicleWeightTons: dto.maxVehicleWeightTons,
    slotSizeMinutes: dto.slotSizeMinutes,
    leadTimeMinutes: dto.leadTimeMinutes,
    now: dto.now,
    slots: (dto.slots ?? []).map((slot) => ({ ...slot })),
  };
}

export function toHold(dto: HoldDto): HoldSession {
  return {
    holdToken: dto.holdToken,
    storeId: dto.storeId,
    rampId: dto.rampId,
    slotStart: dto.slotStart,
    expiresAt: dto.expiresAt,
    maxExpiresAt: dto.maxExpiresAt,
    secondsLeft: dto.secondsLeft,
  };
}

export function toVehicle(dto: VehicleDto): Vehicle {
  return { ...dto, brand: dto.brand ?? null };
}

export function toDriver(dto: DriverDto): Driver {
  return { ...dto, defaultVehicleId: dto.defaultVehicleId ?? null };
}

export function toDriverCredentials(
  dto: DriverCredentialsDto,
): DriverCredentials {
  const { login, password, passwordNotice, ...driver } = dto;
  return { driver: toDriver(driver), login, password, passwordNotice };
}

export function toBooking(dto: BookingDto): Booking {
  return {
    id: dto.id,
    type: dto.type,
    status: dto.status,
    storeId: dto.storeId,
    store: dto.store,
    rampId: dto.rampId,
    slotStart: dto.slotStart,
    slotEnd: dto.slotEnd,
    localDate: dto.localDate,
    localTime: dto.localTime,
    supplierId: dto.supplierId ?? null,
    supplierName: dto.supplierName ?? null,
    vehicle: { ...dto.vehicle, brand: dto.vehicle.brand ?? null },
    driverId: dto.driverId ?? null,
    orderId: dto.orderId ?? null,
    palletsCount: dto.palletsCount,
    delayed: dto.delayed ?? { flag: false, reason: null, eta: null },
    rescheduleOf: dto.rescheduleOf ?? null,
    routeSheetId: dto.routeSheetId ?? null,
    createdAt: dto.createdAt,
    updatedAt: dto.updatedAt,
  };
}

export function toRouteSheet(dto: RouteSheetDto): RouteSheet {
  return {
    routeSheetId: dto.routeSheetId,
    supplierId: dto.supplierId,
    supplierName: dto.supplierName ?? null,
    date: dto.date,
    printVersion: dto.printVersion,
    points: (dto.points ?? []).map((point) => ({
      ...point,
      orderId: point.orderId ?? null,
      driverId: point.driverId ?? null,
    })),
  };
}

export function toRouteSheetAssignment(
  dto: RouteSheetAssignmentDto,
): RouteSheetAssignment {
  return {
    routeSheetId: dto._id,
    supplierId: dto.supplierId,
    date: dto.date,
    entries: (dto.entries ?? []).map((entry) => ({
      ...entry,
      driverId: entry.driverId ?? null,
    })),
    printVersion: dto.printVersion,
  };
}

export function toAuthSession(dto: AuthResultDto): AuthSession {
  return {
    accessToken: dto.accessToken,
    accessExpiresAt: dto.accessExpiresAt,
    expiresIn: dto.expiresIn,
    refreshToken: dto.refreshToken,
    refreshExpiresAt: dto.refreshExpiresAt,
    tokenType: dto.tokenType,
    profile: {
      accountId: dto.profile.accountId,
      login: dto.profile.login,
      role:
        dto.profile.role === 'supplier_operator'
          ? 'supplier_operator'
          : 'supplier_admin',
      contour: dto.profile.contour,
      supplierId: dto.profile.supplierId,
      driverId: dto.profile.driverId ?? null,
      mustChangePassword: dto.profile.mustChangePassword,
    },
  };
}
