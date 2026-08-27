import { Injectable, inject, signal } from '@angular/core';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';

interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>;
  readonly userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

/**
 * Ненавʼязлива підказка «Додати на головний екран» (DRV-02):
 * показується один раз, закривається назавжди.
 */
@Injectable({ providedIn: 'root' })
export class InstallPromptService {
  private readonly storage = inject(LocalStorageService);
  private deferred: BeforeInstallPromptEvent | null = null;

  readonly available = signal(false);

  init(): void {
    if (typeof window === 'undefined' || this.dismissedForever()) {
      return;
    }
    window.addEventListener('beforeinstallprompt', (event: Event) => {
      event.preventDefault();
      this.deferred = event as BeforeInstallPromptEvent;
      this.available.set(true);
    });
  }

  dismissedForever(): boolean {
    return this.storage.getRaw(STORAGE_KEYS.installPromptDismissed) === '1';
  }

  dismissForever(): void {
    this.storage.setRaw(STORAGE_KEYS.installPromptDismissed, '1');
    this.available.set(false);
  }

  async promptInstall(): Promise<void> {
    const deferred = this.deferred;
    this.available.set(false);
    if (!deferred) {
      return;
    }
    try {
      await deferred.prompt();
      await deferred.userChoice;
    } catch {
      /* користувач закрив системний діалог */
    } finally {
      this.deferred = null;
      this.dismissForever();
    }
  }
}
