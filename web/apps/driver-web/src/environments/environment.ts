import type { DriverEnvironment } from './environment.model';

export type { DriverEnvironment };

export const environment: DriverEnvironment = {
  production: false,
  useMocks: true,
  apiBase: '/api/driver/v1',
  pollIntervalMs: 30_000,
  enableServiceWorker: false,
};
