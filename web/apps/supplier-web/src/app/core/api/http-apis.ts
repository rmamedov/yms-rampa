import { Injectable, inject } from '@angular/core';
import { map, type Observable } from 'rxjs';
import {
  AuthApi,
  BookingApi,
  CatalogApi,
  DriverApi,
  RouteSheetApi,
  VehicleApi,
} from './contracts';
import { ApiClient } from './api-client.service';
import {
  itemsOf,
  toAuthSession,
  toBooking,
  toBranch,
  toCity,
  toDriver,
  toDriverCredentials,
  toHold,
  toRouteSheet,
  toRouteSheetAssignment,
  toSlotGrid,
  toVehicle,
  type AuthResultDto,
  type BookingDto,
  type CityDto,
  type DriverCredentialsDto,
  type DriverDto,
  type HoldDto,
  type ListEnvelope,
  type RouteSheetAssignmentDto,
  type RouteSheetDto,
  type SlotGridDto,
  type StoreDto,
  type VehicleDto,
} from './dto';
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

/** Реальні реалізації API постачальника (/api/supplier/v1/...). */

@Injectable()
export class HttpAuthApi extends AuthApi {
  private readonly api = inject(ApiClient);

  override login(login: string, password: string): Observable<AuthSession> {
    return this.api
      .post<AuthResultDto>('/auth/login', { login, password })
      .pipe(map(toAuthSession));
  }

  override refresh(refreshToken: string): Observable<AuthSession> {
    return this.api
      .post<AuthResultDto>('/auth/refresh', { refreshToken })
      .pipe(map(toAuthSession));
  }

  /** Бекенд вимагає refreshToken у тілі — саме він і завершує сесію. */
  override logout(refreshToken: string): Observable<void> {
    return this.api.post<void>('/auth/logout', { refreshToken });
  }
}

@Injectable()
export class HttpCatalogApi extends CatalogApi {
  private readonly api = inject(ApiClient);

  override cities(): Observable<CityItem[]> {
    return this.api
      .get<ListEnvelope<CityDto>>('/cities')
      .pipe(map((response) => itemsOf(response).map(toCity)));
  }

  override branches(city: string): Observable<BranchItem[]> {
    return this.api
      .get<ListEnvelope<StoreDto>>('/stores', { city, perPage: 100 })
      .pipe(map((response) => itemsOf(response).map(toBranch)));
  }

  override branch(storeId: string): Observable<BranchDetail> {
    return this.api.get<StoreDto>(`/stores/${storeId}`).pipe(map(toBranch));
  }

  override slots(storeId: string, date: string): Observable<SlotGrid> {
    return this.api
      .get<SlotGridDto>(`/stores/${storeId}/slots`, { date })
      .pipe(map(toSlotGrid));
  }
}

@Injectable()
export class HttpBookingApi extends BookingApi {
  private readonly api = inject(ApiClient);

  override hold(key: SlotKey): Observable<HoldSession> {
    return this.api.post<HoldDto>('/slots/hold', { ...key }).pipe(map(toHold));
  }

  override extendHold(
    key: SlotKey,
    holdToken: string,
  ): Observable<HoldSession> {
    return this.api
      .patch<HoldDto>('/slots/hold', { ...key, holdToken })
      .pipe(map(toHold));
  }

  override releaseHold(key: SlotKey, holdToken: string): Observable<void> {
    return this.api.delete<void>('/slots/hold', { ...key, holdToken });
  }

  override create(request: CreateBookingRequest): Observable<Booking> {
    return this.api
      .post<BookingDto>('/bookings', bookingBody(request))
      .pipe(map(toBooking));
  }

  override get(bookingId: string): Observable<Booking> {
    return this.api
      .get<BookingDto>(`/bookings/${bookingId}`)
      .pipe(map(toBooking));
  }

  override reschedule(
    bookingId: string,
    request: CreateBookingRequest,
  ): Observable<Booking> {
    return this.api
      .patch<BookingDto>(`/bookings/${bookingId}`, bookingBody(request))
      .pipe(map(toBooking));
  }

  override reassign(
    bookingId: string,
    patch: BookingReassign,
  ): Observable<Booking> {
    const body: Record<string, unknown> = {};
    if ('driverId' in patch) {
      body['driverId'] = patch.driverId;
    }
    if (patch.vehicle) {
      body['vehicle'] = vehicleBody(patch.vehicle);
    }
    return this.api
      .patch<BookingDto>(`/bookings/${bookingId}`, body)
      .pipe(map(toBooking));
  }

  override cancel(bookingId: string, reason?: string): Observable<Booking> {
    return this.api
      .delete<BookingDto>(`/bookings/${bookingId}`, {
        reason: reason?.trim() || undefined,
      })
      .pipe(map(toBooking));
  }
}

@Injectable()
export class HttpRouteSheetApi extends RouteSheetApi {
  private readonly api = inject(ApiClient);

  override detail(date: string): Observable<RouteSheet> {
    return this.api
      .get<RouteSheetDto>('/route-sheets', { date })
      .pipe(map(toRouteSheet));
  }

  override assignDriverToSheet(
    date: string,
    driverId: string,
  ): Observable<RouteSheetAssignment> {
    return this.api
      .post<RouteSheetAssignmentDto>('/route-sheets/driver', { date, driverId })
      .pipe(map(toRouteSheetAssignment));
  }

  override assignDriverToBooking(
    bookingId: string,
    driverId: string | null,
  ): Observable<RouteSheetAssignment> {
    return this.api
      .post<RouteSheetAssignmentDto>('/route-sheets/driver', {
        bookingId,
        driverId,
      })
      .pipe(map(toRouteSheetAssignment));
  }
}

@Injectable()
export class HttpVehicleApi extends VehicleApi {
  private readonly api = inject(ApiClient);

  override list(includeInactive = true): Observable<Vehicle[]> {
    return this.api
      .get<ListEnvelope<VehicleDto>>('/vehicles', { includeInactive })
      .pipe(map((response) => itemsOf(response).map(toVehicle)));
  }

  override create(input: VehicleInput): Observable<Vehicle> {
    return this.api
      .post<VehicleDto>('/vehicles', vehicleBody(input))
      .pipe(map(toVehicle));
  }

  override update(id: string, input: VehicleInput): Observable<Vehicle> {
    return this.api
      .patch<VehicleDto>(`/vehicles/${id}`, vehicleBody(input))
      .pipe(map(toVehicle));
  }

  /** Активність — окремі маршрути, а не поле в PATCH. */
  override setActive(id: string, active: boolean): Observable<Vehicle> {
    return this.api
      .post<VehicleDto>(`/vehicles/${id}/${active ? 'activate' : 'deactivate'}`)
      .pipe(map(toVehicle));
  }

  override remove(id: string): Observable<void> {
    return this.api.delete<void>(`/vehicles/${id}`);
  }
}

@Injectable()
export class HttpDriverApi extends DriverApi {
  private readonly api = inject(ApiClient);

  override list(): Observable<Driver[]> {
    return this.api
      .get<ListEnvelope<DriverDto>>('/drivers')
      .pipe(map((response) => itemsOf(response).map(toDriver)));
  }

  override create(input: DriverInput): Observable<DriverCredentials> {
    return this.api
      .post<DriverCredentialsDto>('/drivers', {
        phone: input.phone,
        firstName: input.firstName,
        lastName: input.lastName,
        defaultVehicleId: input.defaultVehicleId ?? null,
      })
      .pipe(map(toDriverCredentials));
  }

  override regeneratePassword(id: string): Observable<DriverCredentials> {
    return this.api
      .post<DriverCredentialsDto>(`/drivers/${id}/regenerate-password`)
      .pipe(map(toDriverCredentials));
  }

  override setActive(id: string, active: boolean): Observable<Driver> {
    return this.api
      .post<DriverDto>(`/drivers/${id}/${active ? 'activate' : 'deactivate'}`)
      .pipe(map(toDriver));
  }
}

/** Тіло POST /bookings і PATCH /bookings/{id}: авто йде снімком. */
function bookingBody(request: CreateBookingRequest): Record<string, unknown> {
  return {
    storeId: request.storeId,
    rampId: request.rampId,
    slotStart: request.slotStart,
    vehicle: vehicleBody(request.vehicle),
    palletsCount: request.palletsCount,
    orderId: request.orderId ?? null,
    driverId: request.driverId ?? null,
    holdToken: request.holdToken ?? null,
    confirmConflict: request.confirmConflict ?? false,
  };
}

function vehicleBody(input: VehicleInput): Record<string, unknown> {
  return {
    plateNumber: input.plateNumber,
    weightTons: input.weightTons,
    brand: input.brand ?? null,
  };
}
