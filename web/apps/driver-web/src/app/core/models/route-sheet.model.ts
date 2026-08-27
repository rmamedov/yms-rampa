/**
 * Моделі маршрутного листа водія.
 *
 * ДЖЕРЕЛО ІСТИНИ — реальна відповідь booking-service:
 * `GET /api/driver/v1/route-sheet?date=YYYY-MM-DD`
 * (App\Controller\Driver\RouteSheetController + RouteSheetService::forDriver()).
 *
 * Поля названі рівно так, як їх віддає бекенд. У контурі водія НЕМАЄ
 * `slotEnd`, координат філії, снапшота авто (модель/тоннаж), імені водія,
 * прапорця `delayed` та `arrivedAt` — не вигадуємо їх на клієнті.
 */

/** Канонічний статус бронювання (BookingStatus у booking-service, розділ 6.5). */
export type BookingStatus =
  | 'booked'
  | 'arrived'
  | 'unloading'
  | 'completed'
  | 'cancelled'
  | 'no_show'
  | 'rejected';

/**
 * Точка маршрутного листа = одне бронювання.
 * Рівно поля RouteSheetService::point().
 */
export interface RoutePoint {
  readonly bookingId: string;
  readonly city: string;
  readonly storeName: string;
  readonly address: string;
  /** Час слоту «HH:MM» у Europe/Kyiv — обчислює сервер (DRV-05). */
  readonly localTime: string;
  /** Початок слоту в UTC, формат `YYYY-MM-DDTHH:MM:SSZ`. */
  readonly slotStart: string;
  readonly rampId: string;
  /** Може бути порожнім — заповнює постачальник або магазин. */
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly plateNumber: string;
  readonly driverId: string | null;
  readonly status: BookingStatus;
}

/** Маршрутний лист пари «постачальник + дата». */
export interface RouteSheet {
  readonly routeSheetId: string;
  readonly supplierId: string;
  /** Дата листа у Europe/Kyiv, формат YYYY-MM-DD. */
  readonly date: string;
  readonly printVersion: number;
  readonly points: readonly RoutePoint[];
}

/** Тіло відповіді GET /api/driver/v1/route-sheet. */
export interface DriverRouteSheetResponse {
  readonly driverId: string;
  readonly date: string;
  /** На дату може бути кілька листів — по одному на постачальника. */
  readonly routeSheets: readonly RouteSheet[];
}

/**
 * Денний зріз для інтерфейсу: точки всіх листів дати одним списком.
 * Застосунок водія показує день як один маршрут.
 */
export interface DayRouteSheet {
  /** Дата у Europe/Kyiv, YYYY-MM-DD. */
  readonly date: string;
  readonly driverId: string;
  /** Коди листів дати — друкована форма показує їх як «Код листа» (PRN-02). */
  readonly routeSheetIds: readonly string[];
  readonly points: readonly RoutePoint[];
}

/**
 * Дата, на яку у водія є точки (DRV-13).
 *
 * Бекенд переліку дат НЕ віддає — застосунок збирає його сам,
 * опитуючи `GET /route-sheet` на кілька найближчих дат (див. DriverApi).
 */
export interface AvailableDate {
  /** YYYY-MM-DD у Europe/Kyiv. */
  readonly date: string;
  readonly pointCount: number;
}
