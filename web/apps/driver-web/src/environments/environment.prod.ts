import type { DriverEnvironment } from './environment.model';

export type { DriverEnvironment };

export const environment: DriverEnvironment = {
  production: true,
  // Бекенд ще не запущено — прод-збірка демонструє робочі екрани на моках.
  // Переключення на реальний API: useMocks = false.
  useMocks: true,
  apiBase: '/api/driver/v1',
  pollIntervalMs: 30_000,
  enableServiceWorker: true,
};
