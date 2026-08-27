import type { Observable } from 'rxjs';
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
  RouteSheetDetail,
  RouteSheetSummary,
  SlotGrid,
  SlotKey,
  SupplierProfile,
  Vehicle,
  VehicleInput,
} from '../models/models';

/**
 * Контракти доступу до API (шлях /api/supplier/v1/...).
 * Реалізації: HTTP (бекенд) та Mock (in-memory, environment.useMocks).
 */

export abstract class AuthApi {
  abstract login(login: string, password: string): Observable<AuthSession>;
  abstract refresh(refreshToken: string): Observable<AuthTokens>;
  abstract logout(): Observable<void>;
  abstract profile(): Observable<SupplierProfile>;
}

export abstract class CatalogApi {
  /** Міста з активними філіями, дозволеними постачальнику (SUP-CITY-01). */
  abstract cities(): Observable<CityItem[]>;
  abstract branches(city: string): Observable<BranchItem[]>;
  abstract branch(storeId: string): Observable<BranchDetail>;
  /** GET /stores/{storeId}/slots?date=YYYY-MM-DD (GRID-01). */
  abstract slots(storeId: string, date: string): Observable<SlotGrid>;
}

export abstract class BookingApi {
  abstract hold(key: SlotKey): Observable<HoldSession>;
  abstract heartbeat(holdToken: string): Observable<HoldSession>;
  abstract release(holdToken: string): Observable<void>;
  abstract create(request: CreateBookingRequest): Observable<Booking>;
  /** Найближчі активні бронювання (SUP-HOME-01). */
  abstract upcoming(limit: number): Observable<Booking[]>;
  abstract cancel(bookingId: string, reason?: string): Observable<Booking>;
  abstract assignDriver(
    bookingId: string,
    driverId: string | null,
  ): Observable<Booking>;
  /** SUP-RS-07: заміна авто в бронюванні з повторною перевіркою тоннажу. */
  abstract changeVehicle(
    bookingId: string,
    vehicleId: string,
  ): Observable<Booking>;
}

export abstract class RouteSheetApi {
  abstract list(): Observable<RouteSheetSummary[]>;
  abstract detail(date: string): Observable<RouteSheetDetail>;
  abstract assignDriver(
    date: string,
    driverId: string | null,
  ): Observable<RouteSheetDetail>;
}

export abstract class VehicleApi {
  abstract list(): Observable<Vehicle[]>;
  abstract create(input: VehicleInput): Observable<Vehicle>;
  abstract update(id: string, input: VehicleInput): Observable<Vehicle>;
  abstract setActive(id: string, active: boolean): Observable<Vehicle>;
  abstract remove(id: string): Observable<void>;
}

export abstract class DriverApi {
  abstract list(): Observable<Driver[]>;
  abstract create(input: DriverInput): Observable<DriverCreated>;
  abstract regeneratePassword(id: string): Observable<DriverCreated>;
  abstract setActive(id: string, active: boolean): Observable<Driver>;
}
