import { Provider } from '@angular/core';
import { environment } from '../../../environments/environment';
import { AnalyticsApi } from './analytics.api';
import { AuthApi } from './auth.api';
import { StoresApi } from './stores.api';
import { SuppliersApi } from './suppliers.api';
import { SyncApi } from './sync.api';
import {
  HttpAnalyticsApi,
  HttpAuthApi,
  HttpStoresApi,
  HttpSuppliersApi,
  HttpSyncApi,
} from './http/http-apis';
import { MockAnalyticsApi } from './mock/mock-analytics.api';
import { MockAuthApi } from './mock/mock-auth.api';
import { MockStoresApi } from './mock/mock-stores.api';
import { MockSuppliersApi } from './mock/mock-suppliers.api';
import { MockSyncApi } from './mock/mock-sync.api';

/**
 * Вибір реалізації сервісів даних: моки для локальної розробки
 * (environment.useMocks) або HTTP-клієнти контуру /api/admin/v1.
 * Моки повторюють контракт бекенду — коди помилок, форму відповіді
 * та обовʼязкові параметри.
 */
export function provideDataAccess(useMocks = environment.useMocks): Provider[] {
  return useMocks
    ? [
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: StoresApi, useClass: MockStoresApi },
        { provide: SuppliersApi, useClass: MockSuppliersApi },
        { provide: SyncApi, useClass: MockSyncApi },
        { provide: AnalyticsApi, useClass: MockAnalyticsApi },
      ]
    : [
        { provide: AuthApi, useClass: HttpAuthApi },
        { provide: StoresApi, useClass: HttpStoresApi },
        { provide: SuppliersApi, useClass: HttpSuppliersApi },
        { provide: SyncApi, useClass: HttpSyncApi },
        { provide: AnalyticsApi, useClass: HttpAnalyticsApi },
      ];
}
