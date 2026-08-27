/**
 * Моделі даних маршрутного листа водія (розділ 8 SRS).
 * Усі моменти часу — UTC, ISO 8601 (DRV-05).
 */

/** Канонічний статус бронювання (booking.status). */
export type BookingStatus =
  | 'booked'
  | 'arrived'
  | 'unloading'
  | 'completed'
  | 'cancelled'
  | 'no_show';

/** Довідник філії (store-service, синхронізація з MCP Сільпо). */
export interface StoreRef {
  readonly storeId: string;
  /** Номер філії у Сільпо (externalId) — потрібен у друкованій формі, PRN-02. */
  readonly externalId: string;
  readonly name: string;
  readonly city: string;
  readonly address: string;
  /** Може бути відсутня при дефекті даних MCP — тоді маршрут недоступний (DRV-23). */
  readonly latitude?: number | null;
  readonly longitude?: number | null;
}

/** Позначка затримки (DRV-31, DRV-41). */
export interface DelayInfo {
  /** Очікуваний час прибуття, UTC ISO 8601. */
  readonly eta: string | null;
  readonly reason: string | null;
  /** Хто поставив позначку: водій або система (пізнє прибуття, DRV-24). */
  readonly setBy: 'driver' | 'system';
}

/** Точка маршрутного листа = одне бронювання. */
export interface RoutePoint {
  readonly bookingId: string;
  /** Початок слоту, UTC ISO 8601. */
  readonly slotStart: string;
  /** Кінець слоту, UTC ISO 8601. */
  readonly slotEnd: string;
  readonly store: StoreRef;
  readonly rampNumber: string;
  /** Може бути порожнім — тоді водій вводить сам (DRV-17). */
  readonly orderId: string | null;
  readonly pallets: number;
  readonly status: BookingStatus;
  readonly delayed: DelayInfo | null;
  /** Причина скасування, якщо є (DRV-статус cancelled). */
  readonly cancelReason?: string | null;
  /** Фактичний час відмітки «На місці», UTC ISO 8601. */
  readonly arrivedAt?: string | null;
}

/** Маршрутний лист на дату. */
export interface RouteSheet {
  readonly routeSheetId: string;
  /** Дата листа у Europe/Kyiv, формат YYYY-MM-DD. */
  readonly date: string;
  readonly driverId: string;
  readonly driverName: string;
  readonly driverPhone: string;
  readonly supplierName: string;
  readonly vehicle: VehicleRef;
  readonly points: readonly RoutePoint[];
  /** Момент формування відповіді сервером, UTC ISO 8601. */
  readonly updatedAt: string;
}

export interface VehicleRef {
  readonly plate: string;
  readonly model: string;
  /** Тоннаж, т. */
  readonly capacityTons: number;
}

/** Дата, на яку у водія існує маршрутний лист (DRV-13). */
export interface AvailableDate {
  /** YYYY-MM-DD у Europe/Kyiv. */
  readonly date: string;
  readonly pointCount: number;
}

/** Тіло запиту відмітки «На місці» (DRV-27, DRV-34). */
export interface ArrivePayload {
  /** ФАКТИЧНИЙ час натискання кнопки водієм, UTC ISO 8601. */
  readonly pressedAt: string;
  readonly latitude?: number | null;
  readonly longitude?: number | null;
}

/** Тіло запиту позначки затримки (DRV-41). */
export interface DelayPayload {
  /** Очікуваний час прибуття, UTC ISO 8601, у майбутньому. */
  readonly eta: string;
  readonly reason: string;
}

/** Подія realtime-каналу (RT-02). */
export interface StreamEvent {
  readonly eventId: string;
  readonly type:
    | 'booking.arrived'
    | 'booking.unloading'
    | 'booking.completed'
    | 'booking.cancelled'
    | 'booking.delayed'
    | 'booking.rejected'
    | 'booking.reassigned'
    | 'slot.released'
    | 'routesheet.updated';
  readonly occurredAt: string;
  readonly payload: Record<string, unknown>;
}
