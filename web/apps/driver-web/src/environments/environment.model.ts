export interface DriverEnvironment {
  readonly production: boolean;
  /** Увімкнення InMemory/Mock-реалізації сервісів даних (бекенд ще не запущено). */
  readonly useMocks: boolean;
  /** Базовий шлях API за канонічною схемою /api/{contour}/v1/... */
  readonly apiBase: string;
  /** Інтервал фолбек-полінгу для driver-web, мс (RT-04). */
  readonly pollIntervalMs: number;
  /** Реєструвати service worker (PWA, DRV-02). */
  readonly enableServiceWorker: boolean;
}
