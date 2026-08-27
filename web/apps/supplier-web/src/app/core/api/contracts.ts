import type { Observable } from 'rxjs';
import type {
  AuthSession,
  Booking,
  BookingReassign,
  BranchDetail,
  BranchItem,
  CityItem,
  CreateBookingRequest,
  Driver,
  DriverCredentials,
  DriverInput,
  HoldSession,
  RouteSheet,
  RouteSheetAssignment,
  SlotGrid,
  SlotKey,
  Vehicle,
  VehicleInput,
} from '../models/models';

/**
 * Контракти доступу до API постачальника. Кожен метод відповідає РІВНО
 * одному наявному маршруту бекенду (див. docs/api-routes.txt).
 * Реалізації: HTTP (бекенд) та Mock (in-memory, environment.useMocks).
 */

export abstract class AuthApi {
  /** POST /api/supplier/v1/auth/login */
  abstract login(login: string, password: string): Observable<AuthSession>;
  /** POST /api/supplier/v1/auth/refresh — повертає нову пару токенів і профіль. */
  abstract refresh(refreshToken: string): Observable<AuthSession>;
  /** POST /api/supplier/v1/auth/logout — refreshToken обовʼязковий у тілі. */
  abstract logout(refreshToken: string): Observable<void>;
}

export abstract class CatalogApi {
  /** GET /api/supplier/v1/cities (SUP-CITY-01). */
  abstract cities(): Observable<CityItem[]>;
  /** GET /api/supplier/v1/stores?city=… */
  abstract branches(city: string): Observable<BranchItem[]>;
  /** GET /api/supplier/v1/stores/{storeId} */
  abstract branch(storeId: string): Observable<BranchDetail>;
  /** GET /api/supplier/v1/stores/{storeId}/slots?date=YYYY-MM-DD (GRID-01). */
  abstract slots(storeId: string, date: string): Observable<SlotGrid>;
}

export abstract class BookingApi {
  /** POST /api/supplier/v1/slots/hold */
  abstract hold(key: SlotKey): Observable<HoldSession>;
  /** PATCH /api/supplier/v1/slots/hold (HOLD-02). */
  abstract extendHold(key: SlotKey, holdToken: string): Observable<HoldSession>;
  /** DELETE /api/supplier/v1/slots/hold */
  abstract releaseHold(key: SlotKey, holdToken: string): Observable<void>;
  /** POST /api/supplier/v1/bookings (BOOK-01..BOOK-09). */
  abstract create(request: CreateBookingRequest): Observable<Booking>;
  /** GET /api/supplier/v1/bookings/{bookingId} */
  abstract get(bookingId: string): Observable<Booking>;
  /** PATCH /api/supplier/v1/bookings/{bookingId} з новим ключем слота (EDIT-01). */
  abstract reschedule(
    bookingId: string,
    request: CreateBookingRequest,
  ): Observable<Booking>;
  /** PATCH /api/supplier/v1/bookings/{bookingId} — водій та/або авто (EDIT-05). */
  abstract reassign(
    bookingId: string,
    patch: BookingReassign,
  ): Observable<Booking>;
  /** DELETE /api/supplier/v1/bookings/{bookingId} (ST-04, EDIT-03). */
  abstract cancel(bookingId: string, reason?: string): Observable<Booking>;
}

export abstract class RouteSheetApi {
  /** GET /api/supplier/v1/route-sheets?date=YYYY-MM-DD — параметр обовʼязковий. */
  abstract detail(date: string): Observable<RouteSheet>;
  /** POST /api/supplier/v1/route-sheets/driver {date, driverId} (RSHT-02). */
  abstract assignDriverToSheet(
    date: string,
    driverId: string,
  ): Observable<RouteSheetAssignment>;
  /** POST /api/supplier/v1/route-sheets/driver {bookingId, driverId}. */
  abstract assignDriverToBooking(
    bookingId: string,
    driverId: string | null,
  ): Observable<RouteSheetAssignment>;
}

export abstract class VehicleApi {
  /** GET /api/supplier/v1/vehicles?includeInactive=… */
  abstract list(includeInactive?: boolean): Observable<Vehicle[]>;
  /** POST /api/supplier/v1/vehicles */
  abstract create(input: VehicleInput): Observable<Vehicle>;
  /** PATCH /api/supplier/v1/vehicles/{id} */
  abstract update(id: string, input: VehicleInput): Observable<Vehicle>;
  /** POST /api/supplier/v1/vehicles/{id}/activate|deactivate */
  abstract setActive(id: string, active: boolean): Observable<Vehicle>;
  /** DELETE /api/supplier/v1/vehicles/{id} (SUP-VEH-04). */
  abstract remove(id: string): Observable<void>;
}

export abstract class DriverApi {
  /** GET /api/supplier/v1/drivers */
  abstract list(): Observable<Driver[]>;
  /** POST /api/supplier/v1/drivers */
  abstract create(input: DriverInput): Observable<DriverCredentials>;
  /** POST /api/supplier/v1/drivers/{id}/regenerate-password (SUP-DRV-04). */
  abstract regeneratePassword(id: string): Observable<DriverCredentials>;
  /** POST /api/supplier/v1/drivers/{id}/activate|deactivate (SUP-DRV-05). */
  abstract setActive(id: string, active: boolean): Observable<Driver>;
}
