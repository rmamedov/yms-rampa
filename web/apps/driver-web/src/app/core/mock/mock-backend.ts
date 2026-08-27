/**
 * InMemory-«бекенд» для режиму environment.useMocks.
 *
 * Повторює РЕАЛЬНУ поведінку booking-service на маршрутах контуру водія.
 *
 * Читання — `GET /api/driver/v1/route-sheet?date=YYYY-MM-DD`:
 *  - той самий конверт `{driverId, date, routeSheets[]}`;
 *  - ті самі назви полів точки (RouteSheetService::point());
 *  - `slotStart` у форматі `YYYY-MM-DDTHH:MM:SSZ` (без мілісекунд);
 *  - `localTime` рахує «сервер», а не клієнт;
 *  - порожній день — це 200 і `routeSheets: []`, а не 404;
 *  - некоректний `date` — 422 VALIDATION_FAILED, як у контролері;
 *  - проєкція листа НЕ містить `arrivedAt` і `delayed` — рівно як у бекенді.
 *
 * Дії — `arrived`, `delay`, `PATCH {orderId}`: віддають ПОВНЕ представлення
 * бронювання (BookingPresenter::toArray()), тобто іншу форму, ніж точка
 * листа, і повторюють правила DriverBookingService та агрегату Booking:
 *  - «На місці» ідемпотентна (правило «хто перший»);
 *  - перехід поза машиною станів — 409 INVALID_STATUS_TRANSITION;
 *  - причина затримки лише з довідника, ETA лише в майбутньому,
 *    «інше» лише з коментарем — усе 422;
 *  - orderId редагується лише до початку розвантаження — далі 422.
 */
import { Injectable } from '@angular/core';
import type {
  BookingStatus,
  DriverRouteSheetResponse,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';
import {
  DELAY_REASONS,
  DELAY_REASON_REQUIRING_COMMENT,
  NO_DELAY,
  type BookingResponse,
  type DelayState,
} from '../models/booking-action.model';
import { ApiProblemError } from '../models/problem.model';
import { MOCK_STORES } from './stores.fixture';
import {
  addDaysToDateKey,
  formatKyivTime,
  kyivDateKey,
  toBackendIso,
} from '../util/time.util';
import type { DriverProfile } from '../models/auth.model';

/** Профіль водія у формі AccountProfile::toArray() identity-partner-service. */
export const MOCK_DRIVER: DriverProfile = {
  accountId: 'acc-1001',
  login: '+380671234567',
  role: 'driver',
  contour: 'partner',
  supplierId: 'sup-77',
  driverId: 'drv-1001',
  mustChangePassword: false,
};

/** Демо-облікові дані для роботи без бекенду. */
export const MOCK_CREDENTIALS = {
  phone: '+380671234567',
  password: 'Rampa2026',
};

/** Обліковий запис постачальника — для перевірки DRV-10. */
export const MOCK_NON_DRIVER_CREDENTIALS = {
  phone: '+380501112233',
  password: 'Rampa2026',
};

const MOCK_SUPPLIER_ID = MOCK_DRIVER.supplierId;
const MOCK_PLATE = 'AA 4721 OB';

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/** Статуси, у яких водій ще може діяти над точкою (Booking, розділ 6.5). */
const OPEN_FOR_DRIVER: readonly BookingStatus[] = ['booked', 'arrived'];

let seq = 0;
function nextId(prefix: string): string {
  seq += 1;
  return `${prefix}-${seq.toString().padStart(4, '0')}`;
}

function hourFloor(now: number): number {
  return Math.floor(now / 3_600_000) * 3_600_000;
}

/** Внутрішній запис точки: поля листа + стан, який віддають лише дії. */
type StoredPoint = { -readonly [K in keyof RoutePoint]: RoutePoint[K] } & {
  arrivedAt: string | null;
  delayed: DelayState;
};

interface StoredSheet {
  readonly routeSheetId: string;
  readonly supplierId: string;
  readonly date: string;
  readonly printVersion: number;
  readonly points: StoredPoint[];
}

interface SeedPoint {
  offsetMinutes: number;
  status: RoutePoint['status'];
  storeIndex: number;
  orderId: string | null;
  palletsCount: number;
  rampId: string;
}

const TODAY_SEED: SeedPoint[] = [
  { offsetMinutes: -240, status: 'completed', storeIndex: 0, orderId: '4410233', palletsCount: 6, rampId: 'ramp-3' },
  { offsetMinutes: -120, status: 'unloading', storeIndex: 1, orderId: '4410277', palletsCount: 4, rampId: 'ramp-1' },
  { offsetMinutes: -30, status: 'arrived', storeIndex: 2, orderId: null, palletsCount: 8, rampId: 'ramp-2' },
  { offsetMinutes: 180, status: 'booked', storeIndex: 6, orderId: '4410301', palletsCount: 12, rampId: 'ramp-4' },
  { offsetMinutes: 300, status: 'booked', storeIndex: 9, orderId: null, palletsCount: 3, rampId: 'ramp-2' },
];

const TOMORROW_SEED: SeedPoint[] = [
  { offsetMinutes: 8 * 60, status: 'booked', storeIndex: 14, orderId: '4411002', palletsCount: 10, rampId: 'ramp-1' },
  { offsetMinutes: 11 * 60, status: 'booked', storeIndex: 4, orderId: null, palletsCount: 7, rampId: 'ramp-3' },
  { offsetMinutes: 14 * 60, status: 'booked', storeIndex: 8, orderId: '4411045', palletsCount: 9, rampId: 'ramp-2' },
];

const DAY_AFTER_SEED: SeedPoint[] = [
  { offsetMinutes: 9 * 60, status: 'booked', storeIndex: 11, orderId: null, palletsCount: 5, rampId: 'ramp-2' },
  { offsetMinutes: 13 * 60, status: 'booked', storeIndex: 13, orderId: '4412100', palletsCount: 11, rampId: 'ramp-4' },
];

@Injectable({ providedIn: 'root' })
export class MockBackend {
  private sheets = new Map<string, StoredSheet>();
  private seeded = false;

  /** Явна ре-ініціалізація (використовується у тестах). */
  reset(now: number = Date.now()): void {
    this.sheets.clear();
    this.seed(now);
  }

  /**
   * Відповідь driver_route_sheet. `date` за замовчуванням — поточна
   * київська дата, як і в контролері.
   */
  routeSheet(
    date: string = kyivDateKey(),
    now: number = Date.now(),
  ): DriverRouteSheetResponse {
    if (!DATE_PATTERN.test(date)) {
      throw new ApiProblemError(422, {
        type: 'about:blank',
        title: 'Не пройдено валідацію',
        status: 422,
        code: 'VALIDATION_FAILED',
        detail: 'Параметр «date» має бути у форматі YYYY-MM-DD',
      });
    }

    this.ensureSeeded(now);
    const sheet = this.sheets.get(date);

    return {
      driverId: MOCK_DRIVER.driverId as string,
      date,
      // Активні бронювання інших статусів лист не показує (RouteSheetService).
      routeSheets: sheet ? [toWireSheet(sheet)] : [],
    };
  }

  /**
   * DriverBookingService::markArrived — booked → arrived.
   *
   * Ідемпотентність тут КЛЮЧОВА: якщо магазин відмітив прибуття першим,
   * повторний виклик віддає поточний стан без помилки і без другої події.
   */
  markArrived(bookingId: string, now: number = Date.now()): BookingResponse {
    const point = this.point(bookingId, now);

    if (point.status === 'arrived') {
      return toBookingResponse(point);
    }

    if (point.status !== 'booked') {
      throw invalidTransition(point.status);
    }

    point.status = 'arrived';
    point.arrivedAt = toBackendIso(now);

    return toBookingResponse(point);
  }

  /** Booking::setDelay — причина з довідника, ETA лише в майбутньому. */
  reportDelay(
    bookingId: string,
    reason: string,
    eta: string,
    comment: string | null = null,
    now: number = Date.now(),
  ): BookingResponse {
    const point = this.point(bookingId, now);

    if (!OPEN_FOR_DRIVER.includes(point.status)) {
      throw validationFailed(
        'Затримку можна позначити лише для бронювання у статусі «booked» або «arrived»',
      );
    }

    if (!(DELAY_REASONS as readonly string[]).includes(reason)) {
      throw validationFailed(
        `Значення «${reason}» відсутнє в довіднику. Допустимі: ${DELAY_REASONS.join(', ')}`,
      );
    }

    const etaMs = Date.parse(eta);

    if (Number.isNaN(etaMs)) {
      throw validationFailed('Поле «eta» має містити дату в форматі ISO 8601');
    }

    if (etaMs <= now) {
      throw validationFailed('ETA має бути в майбутньому');
    }

    const trimmed = comment?.trim() ?? '';
    const requiresComment = reason === DELAY_REASON_REQUIRING_COMMENT;

    if (requiresComment && trimmed === '') {
      throw validationFailed('Для причини «інше» коментар обовʼязковий');
    }

    point.delayed = {
      flag: true,
      // Для «інше» бекенд склеює причину з коментарем через двокрапку.
      reason: requiresComment ? `${reason}: ${trimmed}` : reason,
      eta: toBackendIso(etaMs),
    };

    return toBookingResponse(point);
  }

  /** Booking::setOrderIdByDriver — лише до початку розвантаження. */
  updateOrderId(
    bookingId: string,
    orderId: string | null,
    now: number = Date.now(),
  ): BookingResponse {
    const point = this.point(bookingId, now);

    if (!OPEN_FOR_DRIVER.includes(point.status)) {
      throw validationFailed(
        'Номер замовлення можна вказати лише до початку розвантаження',
      );
    }

    const trimmed = orderId?.trim() ?? '';
    point.orderId = trimmed === '' ? null : trimmed;

    return toBookingResponse(point);
  }

  /**
   * Точка маршрутного листа цього водія.
   *
   * У моці всі точки належать MOCK_DRIVER, тож 403 ACCESS_DENIED
   * (чужий лист) відтворити нічим — невідоме бронювання дає 404, як
   * і BookingLifecycleService::load().
   */
  private point(bookingId: string, now: number): StoredPoint {
    this.ensureSeeded(now);

    for (const sheet of this.sheets.values()) {
      const found = sheet.points.find((p) => p.bookingId === bookingId);
      if (found) {
        return found;
      }
    }

    throw new ApiProblemError(404, {
      type: 'about:blank',
      title: 'Не знайдено',
      status: 404,
      code: 'BOOKING_NOT_FOUND',
      detail: `Бронювання «${bookingId}» не знайдено`,
    });
  }

  private ensureSeeded(now: number): void {
    if (!this.seeded) {
      this.seed(now);
    }
  }

  private seed(now: number): void {
    const todayKey = kyivDateKey(now);
    this.sheets.set(todayKey, this.buildSheet(todayKey, hourFloor(now), TODAY_SEED));

    const tomorrowKey = addDaysToDateKey(todayKey, 1);
    this.sheets.set(
      tomorrowKey,
      this.buildSheet(tomorrowKey, midnightUtcOfKyivDate(tomorrowKey), TOMORROW_SEED),
    );

    const dayAfterKey = addDaysToDateKey(todayKey, 2);
    this.sheets.set(
      dayAfterKey,
      this.buildSheet(dayAfterKey, midnightUtcOfKyivDate(dayAfterKey), DAY_AFTER_SEED),
    );

    this.seeded = true;
  }

  private buildSheet(dateKey: string, baseMs: number, seed: SeedPoint[]): StoredSheet {
    const points: StoredPoint[] = seed.map((s) => {
      const start = baseMs + s.offsetMinutes * 60_000;
      const store = MOCK_STORES[s.storeIndex % MOCK_STORES.length];
      return {
        bookingId: nextId('bk'),
        city: store.city,
        storeName: store.storeName,
        address: store.address,
        localTime: formatKyivTime(start),
        slotStart: toBackendIso(start),
        rampId: s.rampId,
        orderId: s.orderId,
        palletsCount: s.palletsCount,
        plateNumber: MOCK_PLATE,
        driverId: MOCK_DRIVER.driverId as string,
        status: s.status,
        arrivedAt: s.status === 'booked' ? null : toBackendIso(start),
        delayed: NO_DELAY,
      };
    });
    points.sort((a, b) => a.slotStart.localeCompare(b.slotStart));

    return {
      routeSheetId: `rs-${dateKey}`,
      supplierId: MOCK_SUPPLIER_ID,
      date: dateKey,
      printVersion: 1,
      points,
    };
  }
}

/**
 * Проєкція листа: рівно поля RouteSheetService::point().
 * `arrivedAt` і `delayed` сюди НЕ потрапляють — їх бекенд віддає лише
 * у відповідях дій (BookingPresenter).
 */
function toWireSheet(sheet: StoredSheet): RouteSheet {
  return {
    routeSheetId: sheet.routeSheetId,
    supplierId: sheet.supplierId,
    date: sheet.date,
    printVersion: sheet.printVersion,
    points: sheet.points.map((p) => ({
      bookingId: p.bookingId,
      city: p.city,
      storeName: p.storeName,
      address: p.address,
      localTime: p.localTime,
      slotStart: p.slotStart,
      rampId: p.rampId,
      orderId: p.orderId,
      palletsCount: p.palletsCount,
      plateNumber: p.plateNumber,
      driverId: p.driverId,
      status: p.status,
    })),
  };
}

/** Форма BookingPresenter::toArray() у частині, яку читає застосунок водія. */
function toBookingResponse(point: StoredPoint): BookingResponse {
  return {
    id: point.bookingId,
    status: point.status,
    orderId: point.orderId,
    arrivedAt: point.arrivedAt,
    delayed: point.delayed,
  };
}

function validationFailed(detail: string): ApiProblemError {
  return new ApiProblemError(422, {
    type: 'about:blank',
    title: 'Не пройдено валідацію',
    status: 422,
    code: 'VALIDATION_FAILED',
    detail,
  });
}

/** ST-06: перехід поза машиною станів — 409 з полями from/to. */
function invalidTransition(from: BookingStatus): ApiProblemError {
  return new ApiProblemError(409, {
    type: 'about:blank',
    title: 'Конфлікт',
    status: 409,
    code: 'INVALID_STATUS_TRANSITION',
    detail: `Перехід зі статусу «${from}» у «arrived» неможливий`,
  });
}

/** 00:00 київського дня dateKey у вигляді epoch ms. */
function midnightUtcOfKyivDate(dateKey: string): number {
  const naive = Date.parse(`${dateKey}T00:00:00Z`);
  // Київ = UTC+2/+3; підбираємо момент, київське подання якого — опівніч.
  let guess = naive;
  for (let i = 0; i < 3; i++) {
    const rendered = kyivMidnightOffset(guess);
    if (rendered === 0) {
      break;
    }
    guess -= rendered;
  }
  return guess;
}

function kyivMidnightOffset(at: number): number {
  const fmt = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Europe/Kyiv',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(at));
  const [h, m] = fmt.split(':').map(Number);
  const minutes = h * 60 + m;
  // Мінімальний зсув до опівночі (може бути «назад» або «вперед»).
  return minutes <= 12 * 60 ? minutes * 60_000 : (minutes - 24 * 60) * 60_000;
}
