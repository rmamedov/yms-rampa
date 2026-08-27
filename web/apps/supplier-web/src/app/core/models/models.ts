/**
 * Доменні моделі кабінету постачальника YMS «Рампа».
 *
 * Форма моделей повторює РЕАЛЬНІ відповіді бекенду контуру
 * `/api/supplier/v1/...` (identity-partner-service, store-service,
 * booking-service, partner-service). Усі часові мітки — UTC ISO 8601,
 * відображення — Europe/Kyiv (SUP-UX-03).
 */

export type SupplierRole = 'supplier_admin' | 'supplier_operator';

/**
 * Профіль облікового запису партнерського контуру
 * (AuthResult.profile у identity-partner-service). Імені користувача
 * та назви підприємства цей контур не віддає — див. problems.
 */
export interface SupplierAccount {
  readonly accountId: string;
  readonly login: string;
  readonly role: SupplierRole;
  readonly contour: string;
  readonly supplierId: string;
  readonly driverId: string | null;
  readonly mustChangePassword: boolean;
}

/** Пара токенів POST /auth/login|refresh. */
export interface AuthTokens {
  readonly accessToken: string;
  /** UTC ISO — момент закінчення access-токена. */
  readonly accessExpiresAt: string;
  readonly expiresIn: number;
  readonly refreshToken: string;
  readonly refreshExpiresAt: string;
  readonly tokenType: string;
}

export interface AuthSession extends AuthTokens {
  readonly profile: SupplierAccount;
}

/** Місто з кількістю доступних постачальнику активних філій (SUP-CITY-02). */
export interface CityItem {
  readonly city: string;
  readonly storeCount: number;
}

export interface Ramp {
  readonly rampId: string;
  readonly number: number;
  readonly name: string;
}

/**
 * Магазин у поданні для постачальника (BranchPresenter::supplierView).
 * Список і картка повертають однакову структуру, тому окремої «деталі» немає.
 */
export interface BranchItem {
  readonly storeId: string;
  readonly externalId: string;
  readonly name: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: number | null;
  readonly longitude: number | null;
  readonly phone: string | null;
  readonly ramps: readonly Ramp[];
  readonly maxVehicleWeightTons: number;
  readonly slotSizeMinutes: number;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
}

export type BranchDetail = BranchItem;

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

/** Слот сітки (Domain\Slot\Slot::toArray). */
export interface Slot {
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  /** Локальний час початку слота, «HH:mm». */
  readonly localStart: string;
  readonly state: SlotState;
  readonly selectable: boolean;
  /** GRID-04: слот зарезервовано саме за цим постачальником. */
  readonly reservedForYou?: boolean;
  readonly reservedForSupplierId?: string;
  readonly blockReason?: string;
}

/** Відповідь GET /stores/{storeId}/slots?date=YYYY-MM-DD (GRID-01, GRID-05). */
export interface SlotGrid {
  readonly storeId: string;
  /** Дата в Europe/Kyiv, YYYY-MM-DD. */
  readonly date: string;
  readonly maxVehicleWeightTons: number;
  readonly slotSizeMinutes: number;
  readonly leadTimeMinutes: number;
  /** Серверний now (UTC) для коректних таймерів на клієнті. */
  readonly now: string;
  readonly slots: readonly Slot[];
}

export interface Vehicle {
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

export interface VehicleInput {
  readonly plateNumber: string;
  readonly weightTons: number;
  readonly brand?: string;
}

export interface Driver {
  readonly id: string;
  readonly accountId: string;
  readonly supplierId: string;
  readonly phone: string;
  readonly firstName: string;
  readonly lastName: string;
  /** partner-service зберігає саме `defaultVehicleId`. */
  readonly defaultVehicleId: string | null;
  readonly active: boolean;
  readonly createdAt: string;
  readonly updatedAt: string;
}

export interface DriverInput {
  readonly phone: string;
  readonly firstName: string;
  readonly lastName: string;
  readonly defaultVehicleId?: string;
}

/** Відповідь на створення водія / перегенерацію пароля (SUP-DRV-03). */
export interface DriverCredentials {
  readonly driver: Driver;
  readonly login: string;
  /** Показується рівно один раз. */
  readonly password: string;
  readonly passwordNotice: string;
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

/** Знімок філії на момент бронювання (DATA-13). */
export interface BookingStoreSnapshot {
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
}

/** Знімок параметрів авто на момент бронювання (DATA-13). */
export interface BookingVehicleSnapshot {
  readonly plateNumber: string;
  readonly weightTons: number;
  readonly brand: string | null;
}

/** Прапорець затримки з причиною та ETA (DLY-01). */
export interface BookingDelay {
  readonly flag: boolean;
  readonly reason: string | null;
  readonly eta: string | null;
}

/** BookingPresenter::toArray booking-service. */
export interface Booking {
  readonly id: string;
  readonly type: BookingType;
  readonly status: BookingStatus;
  readonly storeId: string;
  readonly store: BookingStoreSnapshot;
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  /** Локальна дата магазину, YYYY-MM-DD. */
  readonly localDate: string;
  /** Локальний час магазину, «HH:mm». */
  readonly localTime: string;
  readonly supplierId: string | null;
  readonly supplierName: string | null;
  readonly vehicle: BookingVehicleSnapshot;
  readonly driverId: string | null;
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly delayed: BookingDelay;
  readonly rescheduleOf: string | null;
  readonly routeSheetId: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

/**
 * Тіло POST /bookings і PATCH /bookings/{id} у режимі перенесення (EDIT-01).
 * Бекенд приймає СНІМОК авто в полі `vehicle`, а не його ідентифікатор.
 */
export interface CreateBookingRequest {
  readonly storeId: string;
  readonly rampId: string;
  readonly slotStart: string;
  readonly vehicle: VehicleInput;
  readonly palletsCount: number;
  readonly orderId?: string;
  readonly driverId?: string;
  readonly holdToken?: string;
  /** Підтвердження перетину по авто (BOOK-04). */
  readonly confirmConflict?: boolean;
}

/** Зміна водія та/або авто без зміни слота (EDIT-05). */
export interface BookingReassign {
  readonly driverId?: string | null;
  readonly vehicle?: VehicleInput;
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
  readonly maxExpiresAt: string;
  /** Скільки секунд лишилось на момент відповіді сервера. */
  readonly secondsLeft: number;
}

/** Точка друкованого маршрутного листа (RouteSheetService::point). */
export interface RouteSheetPoint {
  readonly bookingId: string;
  readonly city: string;
  readonly storeName: string;
  readonly address: string;
  /** Локальний час слота, «HH:mm». */
  readonly localTime: string;
  readonly slotStart: string;
  readonly rampId: string;
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly plateNumber: string;
  readonly driverId: string | null;
  readonly status: BookingStatus;
}

/** GET /route-sheets?date=YYYY-MM-DD (RSHT-03). */
export interface RouteSheet {
  readonly routeSheetId: string;
  readonly supplierId: string;
  readonly supplierName: string | null;
  readonly date: string;
  readonly printVersion: number;
  readonly points: readonly RouteSheetPoint[];
}

export interface RouteSheetEntry {
  readonly bookingId: string;
  readonly driverId: string | null;
  readonly sortOrder: number;
}

/** Відповідь POST /route-sheets/driver — сам агрегат листа, не друкована форма. */
export interface RouteSheetAssignment {
  readonly routeSheetId: string;
  readonly supplierId: string;
  readonly date: string;
  readonly entries: readonly RouteSheetEntry[];
  readonly printVersion: number;
}

/**
 * Зведення листа для списку. Бекенд не має маршруту «список листів»,
 * тому зведення обчислюється клієнтом із листів за діапазоном дат.
 */
export interface RouteSheetSummary {
  /** Дата листа в Europe/Kyiv, YYYY-MM-DD. */
  readonly date: string;
  readonly pointsCount: number;
  /** Спільний водій листа, якщо він один на всі точки. */
  readonly driverId: string | null;
  readonly archived: boolean;
}

/** Точка маршрутного листа разом із датою листа — для головної сторінки. */
export interface UpcomingDelivery extends RouteSheetPoint {
  readonly date: string;
}

export interface NetworkSettings {
  readonly holdTtlMinutes: number;
  readonly holdMaxMinutes: number;
  readonly maxActiveBookingsPerSupplier: number;
  readonly defaultVisibleDays: number;
}
