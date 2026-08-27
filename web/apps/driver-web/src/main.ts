import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { environment } from './environments/environment';

bootstrapApplication(App, appConfig)
  .then(() => registerServiceWorker())
  .catch((err) => console.error(err));

/** Реєстрація service worker PWA (DRV-02, DRV-33). */
function registerServiceWorker(): void {
  if (!environment.enableServiceWorker) {
    return;
  }
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
    return;
  }
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .catch((error) => console.warn('SW registration failed', error));
  });
}
