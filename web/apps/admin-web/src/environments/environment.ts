export interface AppEnvironment {
  readonly production: boolean;
  /** Бекенд ще не запущено — сервіси даних працюють на InMemory-моках. */
  readonly useMocks: boolean;
  /** Базовий шлях API staff-контуру. */
  readonly apiBaseUrl: string;
  /** Штучна затримка моків, мс (0 у тестах). */
  readonly mockLatencyMs: number;
}

export const environment: AppEnvironment = {
  production: false,
  useMocks: true,
  apiBaseUrl: '/api/admin/v1',
  mockLatencyMs: 120,
};
