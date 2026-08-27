/**
 * Диплінки навігаторів (DRV-21, NAV-02/NAV-03).
 * Використовуються ВИКЛЮЧНО universal links (https), не кастомні схеми.
 *
 * Пункт призначення — КООРДИНАТИ філії: їх віддає точка маршрутного листа
 * (RouteSheetService::point() бере їх зі store-service). Текстова адреса
 * лишається запасним варіантом на випадок філії без координат у довіднику:
 * пошуковий рядок «місто, вулиця» веде приблизно, координати — точно.
 */

export type NavigatorApp = 'google' | 'waze';

/** Мінімум, потрібний для побудови маршруту. */
export interface RouteDestination {
  readonly city: string;
  readonly address: string;
  readonly latitude?: number | null;
  readonly longitude?: number | null;
}

/** «Місто, вулиця» — запасний рядок пошуку для навігатора. */
export function destinationQuery(place: RouteDestination): string {
  return [place.city, place.address].filter(Boolean).join(', ');
}

/**
 * `<lat>,<lng>` або null, якщо координат немає.
 *
 * Нуль — легітимна координата, тому перевіряється саме скінченність числа,
 * а не його «правдивість».
 */
export function coordinates(place: RouteDestination): string | null {
  const { latitude, longitude } = place;

  if (
    typeof latitude !== 'number' ||
    typeof longitude !== 'number' ||
    !Number.isFinite(latitude) ||
    !Number.isFinite(longitude)
  ) {
    return null;
  }

  return `${latitude},${longitude}`;
}

/**
 * Google Maps: https://www.google.com/maps/dir/?api=1&destination=...&travelmode=driving
 *
 * Координати в URL не екрануються: у рядку лише цифри, крапки, кома і мінус —
 * саме той вигляд, який очікує документація параметра `destination`.
 */
export function googleMapsUrl(place: RouteDestination): string {
  const point = coordinates(place);
  const destination = point ?? encodeURIComponent(destinationQuery(place));

  return `https://www.google.com/maps/dir/?api=1&destination=${destination}&travelmode=driving`;
}

/**
 * Waze: за координатами це `?ll=<lat>,<lng>`, за адресою — `?q=<рядок>`.
 * Параметри різні, підставити координати в `q` не можна.
 */
export function wazeUrl(place: RouteDestination): string {
  const point = coordinates(place);

  return point === null
    ? `https://waze.com/ul?q=${encodeURIComponent(destinationQuery(place))}&navigate=yes`
    : `https://waze.com/ul?ll=${point}&navigate=yes`;
}

export function navigatorUrl(app: NavigatorApp, place: RouteDestination): string {
  return app === 'waze' ? wazeUrl(place) : googleMapsUrl(place);
}
