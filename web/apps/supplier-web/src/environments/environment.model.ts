export interface AppEnvironment {
  readonly production: boolean;
  /** Коли true — застосунок працює на in-memory моках без бекенду. */
  readonly useMocks: boolean;
  /** Базовий шлях API-gateway. */
  readonly apiBaseUrl: string;
  /** Затримка відповіді моків, мс (імітація мережі). */
  readonly mockLatencyMs: number;
  /**
   * Демо-доступ, що показується на екрані логіну В МОК-РЕЖИМІ.
   *
   * У прод-конфігурації його немає свідомо: там useMocks=false, підказка не
   * рендериться, а рядок усе одно потрапляв би у публічний бандл і виглядав
   * як справжній пароль. Тому поле необовʼязкове.
   */
  readonly demoLogin?: { readonly email: string; readonly password: string };
}
