/**
 * Тонка обгортка над localStorage: безпечна в SSR/приватному режимі
 * і зручна для підміни у тестах.
 */
import { Injectable } from '@angular/core';

export const STORAGE_KEYS = {
  session: 'yms.driver.session',
  navigatorApp: 'yms.driver.navigator',
  arrivalQueue: 'yms.driver.arrivalQueue',
  routeSheetCache: 'yms.driver.routeSheetCache',
  installPromptDismissed: 'yms.driver.installPromptDismissed',
} as const;

@Injectable({ providedIn: 'root' })
export class LocalStorageService {
  private readonly memory = new Map<string, string>();

  private get backend(): Storage | null {
    try {
      if (typeof localStorage === 'undefined') {
        return null;
      }
      // Перевірка доступності (приватний режим Safari кидає).
      const probe = '__yms_probe__';
      localStorage.setItem(probe, '1');
      localStorage.removeItem(probe);
      return localStorage;
    } catch {
      return null;
    }
  }

  getRaw(key: string): string | null {
    const backend = this.backend;
    if (backend) {
      return backend.getItem(key);
    }
    return this.memory.get(key) ?? null;
  }

  setRaw(key: string, value: string): void {
    const backend = this.backend;
    if (backend) {
      try {
        backend.setItem(key, value);
        return;
      } catch {
        // квота вичерпана — падаємо у памʼять
      }
    }
    this.memory.set(key, value);
  }

  remove(key: string): void {
    const backend = this.backend;
    if (backend) {
      try {
        backend.removeItem(key);
      } catch {
        /* ignore */
      }
    }
    this.memory.delete(key);
  }

  read<T>(key: string, fallback: T): T {
    const raw = this.getRaw(key);
    if (raw === null) {
      return fallback;
    }
    try {
      return JSON.parse(raw) as T;
    } catch {
      return fallback;
    }
  }

  write(key: string, value: unknown): void {
    this.setRaw(key, JSON.stringify(value));
  }

  /** Очищення всіх ключів застосунку (DRV-09, DRV-39). */
  clearAppData(): void {
    for (const key of Object.values(STORAGE_KEYS)) {
      this.remove(key);
    }
  }
}
