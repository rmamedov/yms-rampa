import type { Environment } from './environment.model';

export type { Environment };

export const environment: Environment = {
  production: false,
  useMocks: true,
  apiBaseUrl: '/api/store/v1',
  pollingIntervalMs: 15_000,
  staleThresholdMs: 60_000,
};
