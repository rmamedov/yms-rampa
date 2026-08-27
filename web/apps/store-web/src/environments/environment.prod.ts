import type { Environment } from './environment.model';

export type { Environment };

/**
 * Продакшн-конфігурація. Бекенд ще не запущено, тому мок-режим лишається
 * увімкненим — вимикається одним прапорцем, коли зʼявиться api-gateway.
 */
export const environment: Environment = {
  production: true,
  useMocks: false,
  apiBaseUrl: '/api/store/v1',
  pollingIntervalMs: 15_000,
  staleThresholdMs: 60_000,
};
