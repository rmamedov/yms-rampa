/**
 * InMemory-«бекенд» для режиму environment.useMocks.
 * Тримає маршрутні листи водія у памʼяті та відтворює бізнес-правила розділу 8:
 * вікно відмітки, ідемпотентність arrive, заборону редагування orderId у
 * завершених/скасованих точках, автоматичний прапорець delayed при пізньому прибутті.
 */
import { Injectable } from '@angular/core';
import type {
  ArrivePayload,
  AvailableDate,
  DelayPayload,
  RoutePoint,
  RouteSheet,
  StoreRef,
} from '../models/route-sheet.model';
import { ApiProblemError } from '../models/problem.model';
import { MOCK_STORES } from './stores.fixture';
import { addDaysToDateKey, arriveWindowState, kyivDateKey } from '../util/time.util';
import type { DriverProfile } from '../models/auth.model';

export const MOCK_DRIVER: DriverProfile = {
  driverId: 'drv-1001',
  fullName: 'Петренко Іван Миколайович',
  phone: '+380671234567',
  supplierId: 'sup-77',
  supplierName: 'ТОВ «Волинь Фуд»',
  role: 'driver',
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

const MOCK_VEHICLE = {
  plate: 'AA 4721 OB',
  model: 'Mercedes-Benz Atego',
  capacityTons: 8,
};

let seq = 0;
function nextId(prefix: string): string {
  seq += 1;
  return `${prefix}-${seq.toString().padStart(4, '0')}`;
}

function store(index: number): StoreRef {
  return MOCK_STORES[index % MOCK_STORES.length];
}

/** Магазин без координат — демонструє DRV-23. */
function storeWithoutCoords(index: number): StoreRef {
  const base = store(index);
  return { ...base, latitude: null, longitude: null };
}

function hourFloor(now: number): number {
  return Math.floor(now / 3_600_000) * 3_600_000;
}

interface SeedPoint {
  offsetMinutes: number;
  durationMinutes: number;
  status: RoutePoint['status'];
  storeIndex: number;
  orderId: string | null;
  pallets: number;
  ramp: string;
  noCoords?: boolean;
  cancelReason?: string;
}

const TODAY_SEED: SeedPoint[] = [
  {
    offsetMinutes: -240,
    durationMinutes: 60,
    status: 'completed',
    storeIndex: 0,
    orderId: '4410233',
    pallets: 6,
    ramp: '3',
  },
  {
    offsetMinutes: -120,
    durationMinutes: 60,
    status: 'unloading',
    storeIndex: 1,
    orderId: '4410277',
    pallets: 4,
    ramp: '1',
  },
  {
    offsetMinutes: -30,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 2,
    orderId: null,
    pallets: 8,
    ramp: '2',
  },
  {
    offsetMinutes: 180,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 6,
    orderId: '4410301',
    pallets: 12,
    ramp: '4',
  },
  {
    offsetMinutes: 300,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 9,
    orderId: null,
    pallets: 3,
    ramp: '2',
    noCoords: true,
  },
  {
    offsetMinutes: 420,
    durationMinutes: 60,
    status: 'cancelled',
    storeIndex: 12,
    orderId: '4410355',
    pallets: 5,
    ramp: '1',
    cancelReason: 'Магазин скасував приймання',
  },
];

const TOMORROW_SEED: SeedPoint[] = [
  {
    offsetMinutes: 8 * 60,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 14,
    orderId: '4411002',
    pallets: 10,
    ramp: '1',
  },
  {
    offsetMinutes: 11 * 60,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 18,
    orderId: null,
    pallets: 7,
    ramp: '3',
  },
  {
    offsetMinutes: 14 * 60,
    durationMinutes: 90,
    status: 'booked',
    storeIndex: 21,
    orderId: '4411045',
    pallets: 9,
    ramp: '2',
  },
];

const DAY_AFTER_SEED: SeedPoint[] = [
  {
    offsetMinutes: 9 * 60,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 25,
    orderId: null,
    pallets: 5,
    ramp: '2',
  },
  {
    offsetMinutes: 13 * 60,
    durationMinutes: 60,
    status: 'booked',
    storeIndex: 29,
    orderId: '4412100',
    pallets: 11,
    ramp: '4',
  },
];

@Injectable({ providedIn: 'root' })
export class MockBackend {
  private sheets = new Map<string, RouteSheet>();
  private seeded = false;

  /** Явна ре-ініціалізація (використовується у тестах). */
  reset(now: number = Date.now()): void {
    this.sheets.clear();
    this.seed(now);
  }

  private ensureSeeded(now: number): void {
    if (!this.seeded) {
      this.seed(now);
    }
  }

  private seed(now: number): void {
    const todayKey = kyivDateKey(now);
    const base = hourFloor(now);
    this.sheets.set(
      todayKey,
      this.buildSheet(todayKey, base, TODAY_SEED, now),
    );

    const tomorrowKey = addDaysToDateKey(todayKey, 1);
    const tomorrowBase = midnightUtcOfKyivDate(tomorrowKey);
    this.sheets.set(
      tomorrowKey,
      this.buildSheet(tomorrowKey, tomorrowBase, TOMORROW_SEED, now),
    );

    const dayAfterKey = addDaysToDateKey(todayKey, 2);
    const dayAfterBase = midnightUtcOfKyivDate(dayAfterKey);
    this.sheets.set(
      dayAfterKey,
      this.buildSheet(dayAfterKey, dayAfterBase, DAY_AFTER_SEED, now),
    );

    this.seeded = true;
  }

  private buildSheet(
    dateKey: string,
    baseMs: number,
    seed: SeedPoint[],
    now: number,
  ): RouteSheet {
    const points: RoutePoint[] = seed.map((s) => {
      const start = baseMs + s.offsetMinutes * 60_000;
      const end = start + s.durationMinutes * 60_000;
      const st = s.noCoords ? storeWithoutCoords(s.storeIndex) : store(s.storeIndex);
      return {
        bookingId: nextId('bk'),
        slotStart: new Date(start).toISOString(),
        slotEnd: new Date(end).toISOString(),
        store: st,
        rampNumber: s.ramp,
        orderId: s.orderId,
        pallets: s.pallets,
        status: s.status,
        delayed: null,
        cancelReason: s.cancelReason ?? null,
        arrivedAt:
          s.status === 'arrived' || s.status === 'unloading' || s.status === 'completed'
            ? new Date(start + 5 * 60_000).toISOString()
            : null,
      };
    });
    points.sort((a, b) => a.slotStart.localeCompare(b.slotStart));
    return {
      routeSheetId: `rs-${dateKey}`,
      date: dateKey,
      driverId: MOCK_DRIVER.driverId,
      driverName: MOCK_DRIVER.fullName,
      driverPhone: MOCK_DRIVER.phone,
      supplierName: MOCK_DRIVER.supplierName,
      vehicle: MOCK_VEHICLE,
      points,
      updatedAt: new Date(now).toISOString(),
    };
  }

  availableDates(now: number = Date.now()): AvailableDate[] {
    this.ensureSeeded(now);
    return [...this.sheets.values()]
      .filter((s) => s.points.length > 0)
      .map((s) => ({ date: s.date, pointCount: s.points.length }))
      .sort((a, b) => a.date.localeCompare(b.date));
  }

  /** Повертає лист або null, якщо на дату листа немає. */
  routeSheet(date: string, now: number = Date.now()): RouteSheet | null {
    this.ensureSeeded(now);
    const sheet = this.sheets.get(date);
    if (!sheet) {
      return null;
    }
    return { ...sheet, updatedAt: new Date(now).toISOString() };
  }

  setOrderId(bookingId: string, orderId: string): RoutePoint {
    const found = this.locate(bookingId);
    const trimmed = orderId.trim();
    if (trimmed.length < 1 || trimmed.length > 64) {
      throw new ApiProblemError(422, {
        code: 'VALIDATION_ERROR',
        detail: 'Номер замовлення має містити від 1 до 64 символів',
        violations: [
          { field: 'orderId', code: 'length', message: 'Від 1 до 64 символів' },
        ],
      });
    }
    if (found.point.status === 'completed' || found.point.status === 'cancelled' ||
        found.point.status === 'no_show') {
      throw new ApiProblemError(409, {
        code: 'BOOKING_NOT_EDITABLE',
        detail: 'Редагування номера замовлення для цієї точки недоступне',
      });
    }
    return this.replacePoint(found, { orderId: trimmed });
  }

  arrive(
    bookingId: string,
    payload: ArrivePayload,
    now: number = Date.now(),
  ): RoutePoint {
    const found = this.locate(bookingId);
    const point = found.point;
    if (point.status === 'cancelled' || point.status === 'no_show') {
      throw new ApiProblemError(409, {
        code: 'BOOKING_CANCELLED',
        detail: 'Це бронювання скасовано',
      });
    }
    if (point.status !== 'booked') {
      // Ідемпотентність: магазин або сам водій уже відмітив прибуття (DRV-28, DRV-35).
      throw new ApiProblemError(409, {
        code: 'BOOKING_ALREADY_ARRIVED',
        detail: 'Прибуття вже відмічено',
      });
    }
    const pressedAt = Date.parse(payload.pressedAt);
    const effectiveAt = Number.isNaN(pressedAt) ? now : pressedAt;
    const windowState = arriveWindowState(
      point.slotStart,
      point.slotEnd,
      effectiveAt,
    );
    if (windowState === 'too_early') {
      throw new ApiProblemError(422, {
        code: 'ARRIVAL_WINDOW_NOT_OPEN',
        detail: 'Відмітка стане доступною за 60 хвилин до початку слоту',
      });
    }
    // Прапорець delayed ставить СИСТЕМА за фактичним часом натискання (DRV-24, DRV-34).
    const delayed =
      windowState === 'late'
        ? {
            eta: null,
            reason: 'Прибуття після слоту',
            setBy: 'system' as const,
          }
        : point.delayed;
    return this.replacePoint(found, {
      status: 'arrived',
      arrivedAt: new Date(effectiveAt).toISOString(),
      delayed,
    });
  }

  setDelay(
    bookingId: string,
    payload: DelayPayload,
    now: number = Date.now(),
  ): RoutePoint {
    const found = this.locate(bookingId);
    if (found.point.status !== 'booked') {
      throw new ApiProblemError(409, {
        code: 'BOOKING_NOT_EDITABLE',
        detail: 'Затримку можна повідомити лише для точки в статусі «Очікує виїзду»',
      });
    }
    const eta = Date.parse(payload.eta);
    if (Number.isNaN(eta) || eta <= now) {
      throw new ApiProblemError(422, {
        code: 'DELAY_ETA_IN_PAST',
        detail: 'Час має бути в майбутньому',
      });
    }
    const reason = payload.reason.trim();
    if (!reason) {
      throw new ApiProblemError(422, {
        code: 'VALIDATION_ERROR',
        detail: 'Вкажіть причину',
      });
    }
    return this.replacePoint(found, {
      delayed: { eta: new Date(eta).toISOString(), reason, setBy: 'driver' },
    });
  }

  private locate(bookingId: string): { sheet: RouteSheet; index: number; point: RoutePoint } {
    for (const sheet of this.sheets.values()) {
      const index = sheet.points.findIndex((p) => p.bookingId === bookingId);
      if (index >= 0) {
        return { sheet, index, point: sheet.points[index] };
      }
    }
    // Чужий або неіснуючий ресурс — 404 без розкриття існування (DRV-38).
    throw new ApiProblemError(404, {
      code: 'BOOKING_NOT_FOUND',
      detail: 'Бронювання не знайдено',
    });
  }

  private replacePoint(
    found: { sheet: RouteSheet; index: number; point: RoutePoint },
    patch: Partial<RoutePoint>,
  ): RoutePoint {
    const updated: RoutePoint = { ...found.point, ...patch };
    const points = [...found.sheet.points];
    points[found.index] = updated;
    this.sheets.set(found.sheet.date, {
      ...found.sheet,
      points,
      updatedAt: new Date().toISOString(),
    });
    return updated;
  }
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
