import type { Provider } from '@angular/core';
import { environment } from '../../../environments/environment';
import {
  AuthApi,
  BookingApi,
  CatalogApi,
  DriverApi,
  RouteSheetApi,
  VehicleApi,
} from './contracts';
import {
  HttpAuthApi,
  HttpBookingApi,
  HttpCatalogApi,
  HttpDriverApi,
  HttpRouteSheetApi,
  HttpVehicleApi,
} from './http-apis';
import {
  MockAuthApi,
  MockBookingApi,
  MockCatalogApi,
  MockDriverApi,
  MockRouteSheetApi,
  MockVehicleApi,
} from '../mocks/mock-apis';

/**
 * Вибір реалізації доступу до даних: реальний бекенд або in-memory моки
 * (environment.useMocks) — застосунок повністю працездатний без сервера.
 */
export function provideDataAccess(): Provider[] {
  return environment.useMocks
    ? [
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: CatalogApi, useClass: MockCatalogApi },
        { provide: BookingApi, useClass: MockBookingApi },
        { provide: RouteSheetApi, useClass: MockRouteSheetApi },
        { provide: VehicleApi, useClass: MockVehicleApi },
        { provide: DriverApi, useClass: MockDriverApi },
      ]
    : [
        { provide: AuthApi, useClass: HttpAuthApi },
        { provide: CatalogApi, useClass: HttpCatalogApi },
        { provide: BookingApi, useClass: HttpBookingApi },
        { provide: RouteSheetApi, useClass: HttpRouteSheetApi },
        { provide: VehicleApi, useClass: HttpVehicleApi },
        { provide: DriverApi, useClass: HttpDriverApi },
      ];
}
