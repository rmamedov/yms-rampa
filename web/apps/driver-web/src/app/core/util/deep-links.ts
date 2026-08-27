/**
 * Диплінки навігаторів (DRV-21, NAV-02/NAV-03).
 * Використовуються ВИКЛЮЧНО universal links (https), не кастомні схеми.
 */
import type { StoreRef } from '../models/route-sheet.model';

export type NavigatorApp = 'google' | 'waze';

export function hasCoordinates(store: StoreRef): boolean {
  return (
    typeof store.latitude === 'number' &&
    typeof store.longitude === 'number' &&
    Number.isFinite(store.latitude) &&
    Number.isFinite(store.longitude)
  );
}

/**
 * Google Maps: https://www.google.com/maps/dir/?api=1&destination=lat,lng&travelmode=driving
 * За відсутності координат — URL-encoded адреса (NAV-02).
 */
export function googleMapsUrl(store: StoreRef): string {
  const destination = hasCoordinates(store)
    ? `${store.latitude},${store.longitude}`
    : encodeURIComponent(`${store.city}, ${store.address}`);
  return `https://www.google.com/maps/dir/?api=1&destination=${destination}&travelmode=driving`;
}

/**
 * Waze: https://waze.com/ul?ll=lat,lng&navigate=yes
 * Без координат Waze не підтримує адресний диплінк однозначно — повертаємо пошук q.
 */
export function wazeUrl(store: StoreRef): string {
  if (hasCoordinates(store)) {
    return `https://waze.com/ul?ll=${store.latitude},${store.longitude}&navigate=yes`;
  }
  return `https://waze.com/ul?q=${encodeURIComponent(`${store.city}, ${store.address}`)}&navigate=yes`;
}

export function navigatorUrl(app: NavigatorApp, store: StoreRef): string {
  return app === 'waze' ? wazeUrl(store) : googleMapsUrl(store);
}
