import { Provider } from '@angular/core';
import { environment } from '../../../environments/environment';
import { AnalyticsApi } from './analytics.api';
import { AuditApi } from './audit.api';
import { AuthApi } from './auth.api';
import { StaffApi } from './staff.api';
import { StoresApi } from './stores.api';
import { SuppliersApi } from './suppliers.api';
import { SyncApi } from './sync.api';
import {
  HttpAnalyticsApi,
  HttpAuditApi,
  HttpAuthApi,
  HttpStaffApi,
  HttpStoresApi,
  HttpSuppliersApi,
  HttpSyncApi,
} from './http/http-apis';
import { MockAnalyticsApi } from './mock/mock-analytics.api';
import { MockAuditApi } from './mock/mock-audit.api';
import { MockAuthApi } from './mock/mock-auth.api';
import { MockStaffApi } from './mock/mock-staff.api';
import { MockStoresApi } from './mock/mock-stores.api';
import { MockSuppliersApi } from './mock/mock-suppliers.api';
import { MockSyncApi } from './mock/mock-sync.api';

/**
 * Вибір реалізації сервісів даних: моки (бекенд ще не запущено)
 * або реальні HTTP-клієнти контуру /api/admin/v1.
 */
export function provideDataAccess(useMocks = environment.useMocks): Provider[] {
  return useMocks
    ? [
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: StoresApi, useClass: MockStoresApi },
        { provide: SuppliersApi, useClass: MockSuppliersApi },
        { provide: StaffApi, useClass: MockStaffApi },
        { provide: SyncApi, useClass: MockSyncApi },
        { provide: AnalyticsApi, useClass: MockAnalyticsApi },
        { provide: AuditApi, useClass: MockAuditApi },
      ]
    : [
        { provide: AuthApi, useClass: HttpAuthApi },
        { provide: StoresApi, useClass: HttpStoresApi },
        { provide: SuppliersApi, useClass: HttpSuppliersApi },
        { provide: StaffApi, useClass: HttpStaffApi },
        { provide: SyncApi, useClass: HttpSyncApi },
        { provide: AnalyticsApi, useClass: HttpAnalyticsApi },
        { provide: AuditApi, useClass: HttpAuditApi },
      ];
}
