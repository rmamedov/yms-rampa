export interface AppEnvironment {
  readonly production: boolean;
  /** Коли true — застосунок працює на in-memory моках без бекенду. */
  readonly useMocks: boolean;
  /** Базовий шлях API-gateway. */
  readonly apiBaseUrl: string;
  /** Затримка відповіді моків, мс (імітація мережі). */
  readonly mockLatencyMs: number;
  /** Демо-облікові дані, що показуються на екрані логіну в мок-режимі. */
  readonly demoLogin: { readonly email: string; readonly password: string };
}
