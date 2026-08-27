import { Injectable } from '@angular/core';

export interface Coordinates {
  readonly latitude: number | null;
  readonly longitude: number | null;
}

const EMPTY: Coordinates = { latitude: null, longitude: null };

/**
 * Геолокація — ОПЦІЙНА (DRV-27): відмова в дозволі не блокує відмітку «На місці».
 * Сервіс ніколи не кидає — за будь-якої помилки повертає порожні координати.
 */
@Injectable({ providedIn: 'root' })
export class GeolocationService {
  /**
   * Спроба отримати координати з коротким таймаутом; завжди резолвиться.
   * Відмітка «На місці» не повинна чекати на геолокацію довше за taimeoutMs.
   */
  async current(timeoutMs = 3000): Promise<Coordinates> {
    if (
      typeof navigator === 'undefined' ||
      !('geolocation' in navigator) ||
      !navigator.geolocation
    ) {
      return EMPTY;
    }
    // Явна відмова в дозволі — не турбуємо водія повторним запитом (DRV-27).
    if (await this.isDenied()) {
      return EMPTY;
    }
    return new Promise<Coordinates>((resolve) => {
      let settled = false;
      const done = (value: Coordinates) => {
        if (!settled) {
          settled = true;
          resolve(value);
        }
      };
      const timer = setTimeout(() => done(EMPTY), timeoutMs);
      try {
        navigator.geolocation.getCurrentPosition(
          (position) => {
            clearTimeout(timer);
            done({
              latitude: position.coords.latitude,
              longitude: position.coords.longitude,
            });
          },
          () => {
            clearTimeout(timer);
            done(EMPTY);
          },
          { enableHighAccuracy: false, timeout: timeoutMs, maximumAge: 60_000 },
        );
      } catch {
        clearTimeout(timer);
        done(EMPTY);
      }
    });
  }

  private async isDenied(): Promise<boolean> {
    try {
      const permissions = (navigator as Navigator & { permissions?: Permissions })
        .permissions;
      if (!permissions?.query) {
        return false;
      }
      const status = await permissions.query({
        name: 'geolocation' as PermissionName,
      });
      return status.state === 'denied';
    } catch {
      return false;
    }
  }
}
