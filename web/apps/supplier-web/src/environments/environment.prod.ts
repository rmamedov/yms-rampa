import type { AppEnvironment } from './environment.model';

export type { AppEnvironment };

export const environment: AppEnvironment = {
  production: true,
  // Прод працює проти реального бекенду /api/supplier/v1.
  useMocks: false,
  apiBaseUrl: '/api',
  mockLatencyMs: 0,
  // demoLogin у проді немає: підказка не рендериться (useMocks=false), а рядок
  // із паролем усе одно їхав би в публічний бандл.
};
