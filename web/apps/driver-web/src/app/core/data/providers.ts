import { Provider } from '@angular/core';
import { environment } from '../../../environments/environment';
import { DriverApi } from './driver.api';
import { HttpDriverApi } from './http-driver.api';
import { MockDriverApi } from './mock-driver.api';
import { AuthApi } from '../auth/auth.api';
import { HttpAuthApi } from '../auth/http-auth.api';
import { MockAuthApi } from '../auth/mock-auth.api';

/**
 * Перемикання реального і мок-шару даних через environment.useMocks.
 * Компоненти та стор залежать лише від абстракцій AuthApi / DriverApi.
 */
export function provideDataAccess(): Provider[] {
  return environment.useMocks
    ? [
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: DriverApi, useClass: MockDriverApi },
      ]
    : [
        { provide: AuthApi, useClass: HttpAuthApi },
        { provide: DriverApi, useClass: HttpDriverApi },
      ];
}
