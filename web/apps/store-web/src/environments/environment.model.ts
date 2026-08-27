export interface Environment {
  readonly production: boolean;
  /** Мок-режим: застосунок працює без бекенду. */
  readonly useMocks: boolean;
  /** Базовий шлях API згідно з канонічною схемою /api/{контур}/v1/... */
  readonly apiBaseUrl: string;
  /** Fallback-полінг store-web (RT-04). */
  readonly pollingIntervalMs: number;
  /** Поріг банера «дані можуть бути неактуальні» (STW-12). */
  readonly staleThresholdMs: number;
}
