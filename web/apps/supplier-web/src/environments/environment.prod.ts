import type { AppEnvironment } from './environment.model';

export type { AppEnvironment };

export const environment: AppEnvironment = {
  production: true,
  // Прод працює проти реального бекенду /api/supplier/v1.
  useMocks: false,
  apiBaseUrl: '/api',
  mockLatencyMs: 0,
  demoLogin: { email: 'supplier@rampa.ua', password: 'rampa2026' },
};
