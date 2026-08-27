import type { AppEnvironment } from './environment.model';

export type { AppEnvironment };

export const environment: AppEnvironment = {
  production: true,
  // Бекенд ще не розгорнуто — прод-збірка демонструє застосунок на моках.
  useMocks: true,
  apiBaseUrl: '/api',
  mockLatencyMs: 0,
  demoLogin: { email: 'supplier@rampa.ua', password: 'rampa2026' },
};
