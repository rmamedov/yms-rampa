import type { AppEnvironment } from './environment.model';

export type { AppEnvironment };

export const environment: AppEnvironment = {
  production: false,
  useMocks: true,
  apiBaseUrl: '/api',
  mockLatencyMs: 120,
  demoLogin: { email: 'supplier@rampa.ua', password: 'rampa2026' },
};
