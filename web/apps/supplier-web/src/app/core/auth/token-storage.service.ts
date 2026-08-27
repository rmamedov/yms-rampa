import { Injectable } from '@angular/core';
import type { AuthSession } from '../models/models';

const SESSION_KEY = 'yms.supplier.session';

/** Зберігання сесії у localStorage; безпечне до відсутності storage. */
@Injectable({ providedIn: 'root' })
export class TokenStorage {
  read(): AuthSession | null {
    const session = this.readJson<AuthSession>(SESSION_KEY);
    if (!session?.accessToken || !session.profile) {
      return null;
    }
    return session;
  }

  write(session: AuthSession): void {
    this.writeJson(SESSION_KEY, session);
  }

  clear(): void {
    try {
      localStorage.removeItem(SESSION_KEY);
    } catch {
      /* storage недоступний — ігноруємо */
    }
  }

  private readJson<T>(key: string): T | null {
    try {
      const raw = localStorage.getItem(key);
      return raw ? (JSON.parse(raw) as T) : null;
    } catch {
      return null;
    }
  }

  private writeJson(key: string, value: unknown): void {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* storage недоступний — ігноруємо */
    }
  }
}
