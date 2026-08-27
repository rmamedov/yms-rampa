import { Injectable } from '@angular/core';
import { AuthTokens, StaffProfile, StoreScope } from '../models/auth.model';

const TOKENS_KEY = 'yms.store.tokens';
const PROFILE_KEY = 'yms.store.profile';
const STORE_KEY = 'yms.store.selectedStoreId';
const STORES_KEY = 'yms.store.stores';
const VIEW_MODE_KEY = 'yms.store.viewMode';

/** Токени і користувацькі преференції у localStorage. */
@Injectable({ providedIn: 'root' })
export class TokenStorageService {
  private read<T>(key: string): T | null {
    try {
      const raw = localStorage.getItem(key);
      return raw ? (JSON.parse(raw) as T) : null;
    } catch {
      return null;
    }
  }

  private write(key: string, value: unknown): void {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* приватний режим — ігноруємо */
    }
  }

  private remove(key: string): void {
    try {
      localStorage.removeItem(key);
    } catch {
      /* ignore */
    }
  }

  getTokens(): AuthTokens | null {
    return this.read<AuthTokens>(TOKENS_KEY);
  }

  setTokens(tokens: AuthTokens): void {
    this.write(TOKENS_KEY, tokens);
  }

  getProfile(): StaffProfile | null {
    return this.read<StaffProfile>(PROFILE_KEY);
  }

  setProfile(profile: StaffProfile): void {
    this.write(PROFILE_KEY, profile);
  }

  /**
   * Перелік доступних філій із GET /stores — щоб після перезавантаження
   * перемикач був заповнений одразу, ще до відповіді бекенду.
   */
  getStores(): readonly StoreScope[] | null {
    const list = this.read<StoreScope[]>(STORES_KEY);
    return Array.isArray(list) ? list : null;
  }

  setStores(stores: readonly StoreScope[]): void {
    this.write(STORES_KEY, stores);
  }

  clearStores(): void {
    this.remove(STORES_KEY);
  }

  /** last selected store (STW-03). */
  getSelectedStoreId(): string | null {
    try {
      return localStorage.getItem(STORE_KEY);
    } catch {
      return null;
    }
  }

  setSelectedStoreId(storeId: string): void {
    try {
      localStorage.setItem(STORE_KEY, storeId);
    } catch {
      /* ignore */
    }
  }

  /** Режим дошки per-користувач (STW-06). */
  getViewMode(): string | null {
    try {
      return localStorage.getItem(VIEW_MODE_KEY);
    } catch {
      return null;
    }
  }

  setViewMode(mode: string): void {
    try {
      localStorage.setItem(VIEW_MODE_KEY, mode);
    } catch {
      /* ignore */
    }
  }

  clearSession(): void {
    this.remove(TOKENS_KEY);
    this.remove(PROFILE_KEY);
    this.remove(STORES_KEY);
  }
}
