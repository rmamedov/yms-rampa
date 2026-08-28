/**
 * Моделі маршрутного листа водія.
 *
 * ДЖЕРЕЛО ІСТИНИ — реальна відповідь booking-service:
 * `GET /api/driver/v1/route-sheet?date=YYYY-MM-DD`
 * (App\Controller\Driver\RouteSheetController + RouteSheetService::forDriver()).
 *
 * Поля названі рівно так, як їх віддає бекенд. У контурі водія НЕМАЄ
 * `slotEnd`, снапшота авто (модель/тоннаж) та імені водія — не вигадуємо їх
 * на клієнті.
 */
import { kyivDateKey } from '../util/time.util';

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
 * Прапорець затримки з причиною та ETA — DelayInfo::toArray() (DLY-01).
 *
 * Живе тут, бо це частина ЧИТАННЯ листа: `delayed` є і в точці маршрутного
 * листа, і у відповіді на дію водія, тож обидва контракти описують одну форму.
 */
export interface DelayState {
  readonly flag: boolean;
  /** Для «інше» бекенд склеює причину з коментарем: «інше: <текст>». */
  readonly reason: string | null;
  /** UTC ISO 8601 або null. */
  readonly eta: string | null;
}

export const NO_DELAY: DelayState = { flag: false, reason: null, eta: null };

/**
 * Точка маршрутного листа = одне бронювання.
 * Рівно поля RouteSheetService::point().
 */
export interface RoutePoint {
  readonly bookingId: string;
  readonly city: string;
  readonly storeName: string;
  readonly address: string;
  /**
   * Координати філії з довідника store-service (DRV-21). Саме за ними
   * будується маршрут у навігаторі; null означає, що філія в довіднику без
   * координат — тоді лишається текстова адреса.
   */
  readonly latitude: number | null;
  readonly longitude: number | null;
  /** Час слоту «HH:MM» у Europe/Kyiv — обчислює сервер (DRV-05). */
  readonly localTime: string;
  /** Початок слоту в UTC, формат `YYYY-MM-DDTHH:MM:SSZ`. */
  readonly slotStart: string;
  /** Службовий ідентифікатор рампи — за ним ідуть дії, водієві його не показують. */
  readonly rampId: string;
  /** Номер і назва рампи з довідника філії — рівно те, що написано на воротах. */
  readonly rampNumber: number | null;
  readonly rampName: string | null;
  /** Може бути порожнім — заповнює постачальник або магазин. */
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly plateNumber: string;
  readonly driverId: string | null;
  readonly status: BookingStatus;
  /** Затримка точки — джерело істини для банера на картці (DLY-01). */
  readonly delayed: DelayState;
  /** Час фактичного прибуття, UTC ISO 8601, або null. */
  readonly arrivedAt: string | null;
  /**
   * Прибуття зафіксовано після кінця слоту — позначка запізнення (розділ 8).
   * Рахує домен (Booking::arrivedLate), а не клієнт: у листі немає slotEnd,
   * і власна арифметика тут неминуче розійшлася б із сервером.
   */
  readonly arrivedLate?: boolean;
}

/**
 * Чи можна вже відмітити прибуття на цю точку (розділ 8, D-04).
 *
 * ДЗЕРКАЛО ДОМЕНУ. Джерело істини — ArrivalWindow у booking-service: відмітка
 * приймається не раніше київської доби візиту, і спроба зробити це завтрашній
 * точці повертає 422 ARRIVAL_TOO_EARLY. Тут те саме правило повторене лише
 * для того, щоб водій не тиснув кнопку, яку сервер відхилить, — і щоб екран
 * пояснював, коли вона стане доступною.
 */
export function arrivalAvailable(
  point: RoutePoint,
  now: number = Date.now(),
): boolean {
  return kyivDateKey(now) >= kyivDateKey(new Date(point.slotStart));
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
 * Прочитаний маршрутний лист РАЗОМ з його свіжістю (DRV-33).
 *
 * Свіжість — частина відповіді, а не здогад інтерфейсу: без звʼязку
 * service worker віддає збережену копію звичайним 200, і відрізнити її від
 * щойно завантаженого листа можна лише за службовим заголовком
 * (`x-yms-from-cache`, див. public/sw.js). Саме через відсутність цієї
 * ознаки екран показував «Оновлено HH:MM» на даних добової давності.
 */
export interface RouteSheetLoad {
  /** `null` — на дату точок немає (бекенд віддає `routeSheets: []`, 200). */
  readonly sheet: DayRouteSheet | null;
  /** Відповідь прийшла з кешу service worker, а не з мережі. */
  readonly fromCache: boolean;
  /** Коли цю копію записали в кеш (epoch ms), якщо відомо. */
  readonly cachedAt: number | null;
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

/**
 * Значення, яке водій бачить поруч із підписом «Рампа».
 *
 * Спершу номер: він написаний на воротах, і саме його водій шукає на дворі.
 * Далі назва з довідника — вона потрібна там, де номера немає (у store-service
 * назва рампи без власного імені сама дорівнює «Рампа N», тож поруч із
 * підписом вона дала б «Рампа Рампа 2»). Службовий ідентифікатор лишається
 * останнім засобом: побачити його водій має лише тоді, коли довідник філії
 * недоступний.
 */
export function rampLabel(point: RoutePoint): string {
  if (point.rampNumber !== null) {
    return String(point.rampNumber);
  }

  return point.rampName ?? point.rampId;
}
