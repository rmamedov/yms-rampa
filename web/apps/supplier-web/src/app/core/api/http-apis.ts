import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import {
  AuthApi,
  BookingApi,
  CatalogApi,
  DriverApi,
  RouteSheetApi,
  VehicleApi,
} from './contracts';
import { ApiClient } from './api-client.service';
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

/** Реальні реалізації API постачальника (/api/supplier/v1/...). */

@Injectable()
export class HttpAuthApi extends AuthApi {
  private readonly api = inject(ApiClient);

  override login(login: string, password: string): Observable<AuthSession> {
    return this.api.post<AuthSession>('/auth/login', { login, password });
  }

  override refresh(refreshToken: string): Observable<AuthTokens> {
    return this.api.post<AuthTokens>('/auth/refresh', { refreshToken });
  }

  override logout(): Observable<void> {
    return this.api.post<void>('/auth/logout');
  }

  override profile(): Observable<SupplierProfile> {
    return this.api.get<SupplierProfile>('/profile');
  }
}

@Injectable()
export class HttpCatalogApi extends CatalogApi {
  private readonly api = inject(ApiClient);

  override cities(): Observable<CityItem[]> {
    return this.api.get<CityItem[]>('/cities');
  }

  override branches(city: string): Observable<BranchItem[]> {
    return this.api.get<BranchItem[]>('/stores', { city });
  }

  override branch(storeId: string): Observable<BranchDetail> {
    return this.api.get<BranchDetail>(`/stores/${storeId}`);
  }

  override slots(storeId: string, date: string): Observable<SlotGrid> {
    return this.api.get<SlotGrid>(`/stores/${storeId}/slots`, { date });
  }
}

@Injectable()
export class HttpBookingApi extends BookingApi {
  private readonly api = inject(ApiClient);

  override hold(key: SlotKey): Observable<HoldSession> {
    return this.api.post<HoldSession>('/slots/hold', key);
  }

  override heartbeat(holdToken: string): Observable<HoldSession> {
    return this.api.post<HoldSession>('/slots/hold/heartbeat', { holdToken });
  }

  override release(holdToken: string): Observable<void> {
    return this.api.post<void>('/slots/hold/release', { holdToken });
  }

  override create(request: CreateBookingRequest): Observable<Booking> {
    return this.api.post<Booking>('/bookings', request);
  }

  override upcoming(limit: number): Observable<Booking[]> {
    return this.api.get<Booking[]>('/bookings/upcoming', { limit });
  }

  override cancel(bookingId: string, reason?: string): Observable<Booking> {
    return this.api.post<Booking>(`/bookings/${bookingId}/cancel`, { reason });
  }

  override assignDriver(
    bookingId: string,
    driverId: string | null,
  ): Observable<Booking> {
    return this.api.patch<Booking>(`/bookings/${bookingId}/driver`, {
      driverId,
    });
  }

  override changeVehicle(
    bookingId: string,
    vehicleId: string,
  ): Observable<Booking> {
    return this.api.patch<Booking>(`/bookings/${bookingId}/vehicle`, {
      vehicleId,
    });
  }
}

@Injectable()
export class HttpRouteSheetApi extends RouteSheetApi {
  private readonly api = inject(ApiClient);

  override list(): Observable<RouteSheetSummary[]> {
    return this.api.get<RouteSheetSummary[]>('/route-sheets');
  }

  override detail(date: string): Observable<RouteSheetDetail> {
    return this.api.get<RouteSheetDetail>(`/route-sheets/${date}`);
  }

  override assignDriver(
    date: string,
    driverId: string | null,
  ): Observable<RouteSheetDetail> {
    return this.api.patch<RouteSheetDetail>(`/route-sheets/${date}/driver`, {
      driverId,
    });
  }
}

@Injectable()
export class HttpVehicleApi extends VehicleApi {
  private readonly api = inject(ApiClient);

  override list(): Observable<Vehicle[]> {
    return this.api.get<Vehicle[]>('/vehicles');
  }

  override create(input: VehicleInput): Observable<Vehicle> {
    return this.api.post<Vehicle>('/vehicles', input);
  }

  override update(id: string, input: VehicleInput): Observable<Vehicle> {
    return this.api.put<Vehicle>(`/vehicles/${id}`, input);
  }

  override setActive(id: string, active: boolean): Observable<Vehicle> {
    return this.api.patch<Vehicle>(`/vehicles/${id}`, { active });
  }

  override remove(id: string): Observable<void> {
    return this.api.delete<void>(`/vehicles/${id}`);
  }
}

@Injectable()
export class HttpDriverApi extends DriverApi {
  private readonly api = inject(ApiClient);

  override list(): Observable<Driver[]> {
    return this.api.get<Driver[]>('/drivers');
  }

  override create(input: DriverInput): Observable<DriverCreated> {
    return this.api.post<DriverCreated>('/drivers', input);
  }

  override regeneratePassword(id: string): Observable<DriverCreated> {
    return this.api.post<DriverCreated>(`/drivers/${id}/password`);
  }

  override setActive(id: string, active: boolean): Observable<Driver> {
    return this.api.patch<Driver>(`/drivers/${id}`, { active });
  }
}
