import { Injectable, InjectionToken, inject } from '@angular/core';
import { Observable, defer, of, throwError } from 'rxjs';
import { delay } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import {
  AuthApi,
  BookingApi,
  CatalogApi,
  DriverApi,
  RouteSheetApi,
  VehicleApi,
} from '../api/contracts';
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
import { MockBackend } from './mock-backend';

export const MOCK_BACKEND = new InjectionToken<MockBackend>('MOCK_BACKEND', {
  providedIn: 'root',
  factory: () => new MockBackend(),
});

function respond<T>(produce: () => T): Observable<T> {
  return defer(() => {
    try {
      return of(produce());
    } catch (error) {
      return throwError(() => error);
    }
  }).pipe(delay(environment.mockLatencyMs));
}

@Injectable()
export class MockAuthApi extends AuthApi {
  private readonly backend = inject(MOCK_BACKEND);

  override login(login: string, password: string): Observable<AuthSession> {
    return respond(() => this.backend.login(login, password));
  }

  override refresh(refreshToken: string): Observable<AuthSession> {
    return respond(() => this.backend.refresh(refreshToken));
  }

  override logout(refreshToken: string): Observable<void> {
    return respond(() => this.backend.logout(refreshToken));
  }
}

@Injectable()
export class MockCatalogApi extends CatalogApi {
  private readonly backend = inject(MOCK_BACKEND);

  override cities(): Observable<CityItem[]> {
    return respond(() => this.backend.cities());
  }

  override branches(city: string): Observable<BranchItem[]> {
    return respond(() => this.backend.branches(city));
  }

  override branch(storeId: string): Observable<BranchDetail> {
    return respond(() => this.backend.branch(storeId));
  }

  override slots(storeId: string, date: string): Observable<SlotGrid> {
    return respond(() => this.backend.slots(storeId, date));
  }
}

@Injectable()
export class MockBookingApi extends BookingApi {
  private readonly backend = inject(MOCK_BACKEND);

  override hold(key: SlotKey): Observable<HoldSession> {
    return respond(() => this.backend.hold(key));
  }

  override extendHold(
    key: SlotKey,
    holdToken: string,
  ): Observable<HoldSession> {
    return respond(() => this.backend.extendHold(key, holdToken));
  }

  override releaseHold(key: SlotKey, holdToken: string): Observable<void> {
    return respond(() => this.backend.releaseHold(key, holdToken));
  }

  override create(request: CreateBookingRequest): Observable<Booking> {
    return respond(() => this.backend.createBooking(request));
  }

  override get(bookingId: string): Observable<Booking> {
    return respond(() => this.backend.booking(bookingId));
  }

  override reschedule(
    bookingId: string,
    request: CreateBookingRequest,
  ): Observable<Booking> {
    return respond(() => this.backend.reschedule(bookingId, request));
  }

  override reassign(
    bookingId: string,
    patch: BookingReassign,
  ): Observable<Booking> {
    return respond(() => this.backend.reassignBooking(bookingId, patch));
  }

  override cancel(bookingId: string, reason?: string): Observable<Booking> {
    return respond(() => this.backend.cancelBooking(bookingId, reason));
  }
}

@Injectable()
export class MockRouteSheetApi extends RouteSheetApi {
  private readonly backend = inject(MOCK_BACKEND);

  override detail(date: string): Observable<RouteSheet> {
    return respond(() => this.backend.routeSheet(date));
  }

  override assignDriverToSheet(
    date: string,
    driverId: string,
  ): Observable<RouteSheetAssignment> {
    return respond(() => this.backend.assignDriverToSheet(date, driverId));
  }

  override assignDriverToBooking(
    bookingId: string,
    driverId: string | null,
  ): Observable<RouteSheetAssignment> {
    return respond(() =>
      this.backend.assignDriverToBooking(bookingId, driverId),
    );
  }
}

@Injectable()
export class MockVehicleApi extends VehicleApi {
  private readonly backend = inject(MOCK_BACKEND);

  override list(includeInactive = true): Observable<Vehicle[]> {
    return respond(() => this.backend.listVehicles(includeInactive));
  }

  override create(input: VehicleInput): Observable<Vehicle> {
    return respond(() => this.backend.createVehicle(input));
  }

  override update(id: string, input: VehicleInput): Observable<Vehicle> {
    return respond(() => this.backend.updateVehicle(id, input));
  }

  override setActive(id: string, active: boolean): Observable<Vehicle> {
    return respond(() => this.backend.setVehicleActive(id, active));
  }

  override remove(id: string): Observable<void> {
    return respond(() => this.backend.removeVehicle(id));
  }
}

@Injectable()
export class MockDriverApi extends DriverApi {
  private readonly backend = inject(MOCK_BACKEND);

  override list(): Observable<Driver[]> {
    return respond(() => this.backend.listDrivers());
  }

  override create(input: DriverInput): Observable<DriverCredentials> {
    return respond(() => this.backend.createDriver(input));
  }

  override regeneratePassword(id: string): Observable<DriverCredentials> {
    return respond(() => this.backend.regenerateDriverPassword(id));
  }

  override setActive(id: string, active: boolean): Observable<Driver> {
    return respond(() => this.backend.setDriverActive(id, active));
  }
}
