import {
  ApplicationConfig,
  Provider,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { environment } from '../environments/environment';
import { appRoutes } from './app.routes';
import { authInterceptor } from './core/api/auth.interceptor';
import { AuthGateway, StoreGateway } from './core/data/gateways';
import { HttpAuthGateway } from './core/data/http-auth.gateway';
import { HttpStoreGateway } from './core/data/http-store.gateway';
import { MockAuthGateway, MockStoreGateway } from './core/data/mock.gateways';

/** Мок-режим вмикається одним прапорцем environment.useMocks. */
const dataProviders: Provider[] = environment.useMocks
  ? [
      { provide: AuthGateway, useClass: MockAuthGateway },
      { provide: StoreGateway, useClass: MockStoreGateway },
    ]
  : [
      { provide: AuthGateway, useClass: HttpAuthGateway },
      { provide: StoreGateway, useClass: HttpStoreGateway },
    ];

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(appRoutes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([authInterceptor])),
    ...dataProviders,
  ],
};
