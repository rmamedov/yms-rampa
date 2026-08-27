import type {
  DayRouteSheet,
  DriverRouteSheetResponse,
  RoutePoint,
} from '../models/route-sheet.model';

/**
 * Згортає відповідь `GET /api/driver/v1/route-sheet` у денний зріз для UI.
 *
 * Бекенд повертає конверт `{driverId, date, routeSheets[]}`; на дату може
 * припасти кілька листів (по одному на постачальника). Порожній день —
 * це `routeSheets: []` і статус 200, а не 404.
 *
 * Точки сортуються за часом слоту: бекенд упорядковує їх у межах ОДНОГО
 * листа, тож при склеюванні кількох порядок треба відновити.
 */
export function toDayRouteSheet(
  response: DriverRouteSheetResponse,
): DayRouteSheet | null {
  const sheets = response.routeSheets ?? [];
  const points: RoutePoint[] = sheets.flatMap((sheet) => [...sheet.points]);

  if (points.length === 0) {
    return null;
  }

  points.sort((a, b) => a.slotStart.localeCompare(b.slotStart));

  return {
    date: response.date,
    driverId: response.driverId,
    routeSheetIds: sheets.map((sheet) => sheet.routeSheetId),
    points,
  };
}
