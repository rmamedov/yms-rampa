/**
 * Диплінки навігаторів (DRV-21, NAV-02/NAV-03).
 * Використовуються ВИКЛЮЧНО universal links (https), не кастомні схеми.
 *
 * Координат філії у контурі водія бекенд не віддає (у точці листа є лише
 * місто, назва та адреса), тому пунктом призначення завжди йде адреса.
 */

export type NavigatorApp = 'google' | 'waze';

/** Мінімум, потрібний для побудови маршруту. */
export interface RouteDestination {
  readonly city: string;
  readonly address: string;
}

/** «Місто, вулиця» — рядок пошуку для навігатора. */
export function destinationQuery(place: RouteDestination): string {
  return [place.city, place.address].filter(Boolean).join(', ');
}

/** Google Maps: https://www.google.com/maps/dir/?api=1&destination=...&travelmode=driving */
export function googleMapsUrl(place: RouteDestination): string {
  const destination = encodeURIComponent(destinationQuery(place));
  return `https://www.google.com/maps/dir/?api=1&destination=${destination}&travelmode=driving`;
}

/** Waze: https://waze.com/ul?q=...&navigate=yes */
export function wazeUrl(place: RouteDestination): string {
  return `https://waze.com/ul?q=${encodeURIComponent(destinationQuery(place))}&navigate=yes`;
}

export function navigatorUrl(app: NavigatorApp, place: RouteDestination): string {
  return app === 'waze' ? wazeUrl(place) : googleMapsUrl(place);
}
