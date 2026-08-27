import type { DriverEnvironment } from './environment.model';

export type { DriverEnvironment };

export const environment: DriverEnvironment = {
  production: true,
  // Прод працює з реальним бекендом через api-gateway; моки лишаються
  // тільки для розробки (environment.ts, useMocks: true).
  useMocks: false,
  apiBase: '/api/driver/v1',
  pollIntervalMs: 30_000,
  enableServiceWorker: true,
};
