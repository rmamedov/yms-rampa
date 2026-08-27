import { Injectable } from '@angular/core';
import type { AuthSession, AuthTokens, SupplierUser } from '../models/models';

const TOKENS_KEY = 'yms.supplier.tokens';
const USER_KEY = 'yms.supplier.user';

/** Зберігання токенів у localStorage; безпечне до відсутності storage. */
@Injectable({ providedIn: 'root' })
export class TokenStorage {
  read(): AuthSession | null {
    const tokens = this.readJson<AuthTokens>(TOKENS_KEY);
    const user = this.readJson<SupplierUser>(USER_KEY);
    if (!tokens || !user) {
      return null;
    }
    return { ...tokens, user };
  }

  write(session: AuthSession): void {
    const { user, ...tokens } = session;
    this.writeJson(TOKENS_KEY, tokens);
    this.writeJson(USER_KEY, user);
  }

  updateTokens(tokens: AuthTokens): void {
    this.writeJson(TOKENS_KEY, tokens);
  }

  clear(): void {
    try {
      localStorage.removeItem(TOKENS_KEY);
      localStorage.removeItem(USER_KEY);
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
