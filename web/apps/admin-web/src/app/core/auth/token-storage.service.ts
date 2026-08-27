import { Injectable } from '@angular/core';
import { AuthSession } from '../models';

const SESSION_KEY = 'yms.admin.session';

/** Токени зберігаються у localStorage; доступ обгорнутий try/catch (private mode). */
@Injectable({ providedIn: 'root' })
export class TokenStorageService {
  read(): AuthSession | null {
    try {
      const raw = localStorage.getItem(SESSION_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw) as AuthSession;
      if (!parsed?.tokens?.accessToken || !parsed?.user?.role) {
        return null;
      }
      return parsed;
    } catch {
      return null;
    }
  }

  write(session: AuthSession): void {
    try {
      localStorage.setItem(SESSION_KEY, JSON.stringify(session));
    } catch {
      /* сховище недоступне — працюємо в памʼяті */
    }
  }

  clear(): void {
    try {
      localStorage.removeItem(SESSION_KEY);
    } catch {
      /* ignore */
    }
  }
}
