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

  override refresh(refreshToken: string): Observable<AuthTokens> {
    return respond(() => this.backend.refresh(refreshToken));
  }

  override logout(): Observable<void> {
    return respond(() => undefined);
  }

  override profile(): Observable<SupplierProfile> {
    return respond(() => this.backend.profile);
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

  override heartbeat(holdToken: string): Observable<HoldSession> {
    return respond(() => this.backend.heartbeat(holdToken));
  }

  override release(holdToken: string): Observable<void> {
    return respond(() => this.backend.release(holdToken));
  }

  override create(request: CreateBookingRequest): Observable<Booking> {
    return respond(() => this.backend.createBooking(request));
  }

  override upcoming(limit: number): Observable<Booking[]> {
    return respond(() => this.backend.upcoming(limit));
  }

  override cancel(bookingId: string, reason?: string): Observable<Booking> {
    return respond(() => this.backend.cancelBooking(bookingId, reason));
  }

  override assignDriver(
    bookingId: string,
    driverId: string | null,
  ): Observable<Booking> {
    return respond(() => this.backend.assignDriverToBooking(bookingId, driverId));
  }

  override changeVehicle(
    bookingId: string,
    vehicleId: string,
  ): Observable<Booking> {
    return respond(() => this.backend.changeBookingVehicle(bookingId, vehicleId));
  }
}

@Injectable()
export class MockRouteSheetApi extends RouteSheetApi {
  private readonly backend = inject(MOCK_BACKEND);

  override list(): Observable<RouteSheetSummary[]> {
    return respond(() => this.backend.routeSheets());
  }

  override detail(date: string): Observable<RouteSheetDetail> {
    return respond(() => this.backend.routeSheet(date));
  }

  override assignDriver(
    date: string,
    driverId: string | null,
  ): Observable<RouteSheetDetail> {
    return respond(() => this.backend.assignDriverToSheet(date, driverId));
  }
}

@Injectable()
export class MockVehicleApi extends VehicleApi {
  private readonly backend = inject(MOCK_BACKEND);

  override list(): Observable<Vehicle[]> {
    return respond(() => this.backend.listVehicles());
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

  override create(input: DriverInput): Observable<DriverCreated> {
    return respond(() => this.backend.createDriver(input));
  }

  override regeneratePassword(id: string): Observable<DriverCreated> {
    return respond(() => this.backend.regenerateDriverPassword(id));
  }

  override setActive(id: string, active: boolean): Observable<Driver> {
    return respond(() => this.backend.setDriverActive(id, active));
  }
}
