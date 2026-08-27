import { Injectable, inject, signal } from '@angular/core';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import {
  navigatorUrl,
  type NavigatorApp,
  type RouteDestination,
} from '../util/deep-links';

/**
 * Памʼятає останній обраний навігатор (DRV-22, NAV-04) і відкриває диплінк
 * у новій вкладці/зовнішньому застосунку, не руйнуючи сесію PWA (NAV-03).
 */
@Injectable({ providedIn: 'root' })
export class NavigatorPreferenceService {
  private readonly storage = inject(LocalStorageService);

  private readonly preferredSignal = signal<NavigatorApp | null>(this.load());
  readonly preferred = this.preferredSignal.asReadonly();

  set(app: NavigatorApp): void {
    this.preferredSignal.set(app);
    this.storage.setRaw(STORAGE_KEYS.navigatorApp, app);
  }

  reset(): void {
    this.preferredSignal.set(null);
    this.storage.remove(STORAGE_KEYS.navigatorApp);
  }

  /** Побудувати URL і відкрити його. Повертає URL, який було відкрито. */
  openRoute(app: NavigatorApp, place: RouteDestination, remember = true): string {
    const url = navigatorUrl(app, place);
    if (remember) {
      this.set(app);
    }
    this.openExternal(url);
    return url;
  }

  openExternal(url: string): void {
    if (typeof window === 'undefined' || typeof window.open !== 'function') {
      return;
    }
    window.open(url, '_blank', 'noopener');
  }

  private load(): NavigatorApp | null {
    const raw = this.storage.getRaw(STORAGE_KEYS.navigatorApp);
    return raw === 'google' || raw === 'waze' ? raw : null;
  }
}
