import { Injectable, signal } from '@angular/core';

/**
 * Стан мережі. Джерело — navigator.onLine + події online/offline.
 * Виділено в сервіс, щоб офлайн-логіку можна було детерміновано тестувати.
 */
@Injectable({ providedIn: 'root' })
export class NetworkService {
  private readonly onlineSignal = signal(readInitialOnline());
  readonly online = this.onlineSignal.asReadonly();

  private listeners: Array<() => void> = [];

  constructor() {
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      const goOnline = () => this.onlineSignal.set(true);
      const goOffline = () => this.onlineSignal.set(false);
      window.addEventListener('online', goOnline);
      window.addEventListener('offline', goOffline);
      this.listeners.push(() => window.removeEventListener('online', goOnline));
      this.listeners.push(() => window.removeEventListener('offline', goOffline));
    }
  }

  /** Явна установка стану (використовується у тестах і при мережевій помилці запиту). */
  setOnline(value: boolean): void {
    this.onlineSignal.set(value);
  }

  destroy(): void {
    for (const off of this.listeners) {
      off();
    }
    this.listeners = [];
  }
}

function readInitialOnline(): boolean {
  if (typeof navigator === 'undefined' || typeof navigator.onLine !== 'boolean') {
    return true;
  }
  return navigator.onLine;
}
