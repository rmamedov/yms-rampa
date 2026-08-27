import {
  WireBooking,
  WireStaffUser,
  WireStatusChange,
  WireStoreSnapshot,
} from '../api/wire.model';
import { StaffRole } from '../models/auth.model';
import { Ramp, StoreConfig, SupplierRef } from '../models/store.model';
import {
  isoDayOfWeek,
  kyivToUtcIso,
  parseHhMm,
  toKyivDateKey,
} from '../util/date.util';
import { BRANCHES } from './branches.fixture';

/**
 * Дані мок-режиму. Мок ЗАВЖДИ віддає структури у формі реального бекенду
 * (див. api/wire.model.ts), тому розбіжність контрактів помітна одразу.
 */

/** Детермінований PRNG (mulberry32) — однакові дані між перезавантаженнями. */
export function makeRng(seed: string): () => number {
  let h = 2166136261;
  for (let i = 0; i < seed.length; i++) {
    h ^= seed.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  let a = h >>> 0;
  return () => {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export const DEFAULT_RAMPS: readonly Ramp[] = [
  { rampId: 'r1', name: 'Рампа 1', active: true },
  { rampId: 'r2', name: 'Рампа 2', active: true },
  { rampId: 'r3', name: 'Рампа 3', active: true },
  { rampId: 'r4', name: 'Рампа 4', active: true },
];

export const SUPPLIERS: readonly SupplierRef[] = [
  { supplierId: 'sp-01', name: 'ТОВ «Молокія»' },
  { supplierId: 'sp-02', name: 'ПрАТ «Оболонь»' },
  { supplierId: 'sp-03', name: 'ТОВ «Чумак»' },
  { supplierId: 'sp-04', name: 'ТОВ «Верес»' },
  { supplierId: 'sp-05', name: 'ПрАТ «Київхліб»' },
  { supplierId: 'sp-06', name: 'ТОВ «Терра Фуд»' },
  { supplierId: 'sp-07', name: 'ТОВ «Рошен»' },
  { supplierId: 'sp-08', name: 'ТОВ «Сандора»' },
  { supplierId: 'sp-09', name: 'ТОВ «Данон Дніпро»' },
  { supplierId: 'sp-10', name: 'ТОВ «Гетьман»' },
];

const DRIVER_IDS: readonly string[] = [
  'dr-01',
  'dr-02',
  'dr-03',
  'dr-04',
  'dr-05',
  'dr-06',
];

const PLATE_PREFIXES = ['AA', 'AI', 'AX', 'BC', 'CA', 'KA'];
const PLATE_SUFFIXES = ['BB', 'IP', 'KX', 'MM', 'OP', 'TT'];
const BRANDS = ['MAN', 'Volvo', 'Scania', 'DAF', 'Mercedes-Benz', 'Renault'];

/** Довідник філій мок-режиму: storeId → снапшот, який віддає бекенд. */
export interface MockStore extends WireStoreSnapshot {
  readonly storeId: string;
}

function branchToStore(index: number): MockStore {
  const branch = BRANCHES[index % BRANCHES.length];
  return {
    storeId: branch.branchId,
    externalId: branch.externalId,
    displayName: `Сільпо №${branch.externalId}`,
    city: branch.city,
    address: branch.address,
  };
}

export function mockStores(count: number, offset = 0): MockStore[] {
  return Array.from({ length: count }, (_, i) => branchToStore(offset + i));
}

/** Усі філії, доступні мок-режиму (обʼєднання скоупів демо-користувачів). */
export const MOCK_STORES: readonly MockStore[] = mockStores(6);

export function findMockStore(storeId: string): MockStore | null {
  return MOCK_STORES.find((s) => s.storeId === storeId) ?? null;
}

const STORE_PERMISSIONS: readonly string[] = [
  'store.read',
  'slot.read',
  'booking.read.all',
  'booking.create_walk_in',
  'booking.mark_arrived',
  'booking.mark_unloading',
  'booking.mark_unloaded',
  'booking.mark_no_show',
  'booking.mark_delayed',
  'booking.reject',
  'booking.reassign_ramp',
];

const ROLE_LABELS: Readonly<Record<StaffRole, string>> = {
  super_admin: 'Суперадміністратор',
  network_manager: 'Менеджер мережі',
  store_manager: 'Керівник магазину',
  store_operator: 'Приймальник магазину',
  analyst: 'Аналітик',
  supplier_admin: 'Адміністратор постачальника',
  supplier_operator: 'Оператор постачальника',
  driver: 'Водій',
};

function mockUser(
  id: string,
  fullName: string,
  email: string,
  role: StaffRole,
  storeIds: readonly string[],
  networkWide = false,
): WireStaffUser {
  return {
    id,
    email,
    fullName,
    role,
    roleLabel: ROLE_LABELS[role],
    scope: { storeIds, networkWide },
    twoFactorEnabled: false,
    permissions: STORE_PERMISSIONS,
  };
}

/** Демо-користувачі мок-режиму — у формі `LoginResult::profile()`. */
export const MOCK_USERS: readonly WireStaffUser[] = [
  mockUser(
    'u-operator',
    'Оксана Литвин',
    'operator@silpo.ua',
    'store_operator',
    MOCK_STORES.slice(0, 2).map((s) => s.storeId),
  ),
  mockUser(
    'u-manager',
    'Дмитро Савченко',
    'manager@silpo.ua',
    'store_manager',
    MOCK_STORES.slice(2, 5).map((s) => s.storeId),
  ),
  mockUser(
    'u-single',
    'Ірина Панченко',
    'single@silpo.ua',
    'store_operator',
    MOCK_STORES.slice(5, 6).map((s) => s.storeId),
  ),
  // RBAC-16: мережева роль поза контуром магазину — доступу до store-web немає.
  mockUser(
    'u-outsider',
    'Тарас Гнатюк',
    'admin@silpo.ua',
    'network_manager',
    [],
    true,
  ),
];

export function buildStoreConfig(store: MockStore): StoreConfig {
  const rng = makeRng(`config:${store.storeId}`);
  const rampCount = 3 + Math.floor(rng() * 2);
  return {
    storeId: store.storeId,
    externalId: store.externalId,
    displayName: store.displayName,
    city: store.city,
    address: store.address,
    ramps: DEFAULT_RAMPS.slice(0, rampCount),
    slotSizeMinutes: 30,
    receivingWindows: [
      { dayOfWeek: 1, intervals: [{ from: '08:00', to: '20:00' }] },
      { dayOfWeek: 2, intervals: [{ from: '08:00', to: '20:00' }] },
      { dayOfWeek: 3, intervals: [{ from: '08:00', to: '20:00' }] },
      { dayOfWeek: 4, intervals: [{ from: '08:00', to: '20:00' }] },
      { dayOfWeek: 5, intervals: [{ from: '08:00', to: '20:00' }] },
      { dayOfWeek: 6, intervals: [{ from: '09:00', to: '18:00' }] },
      { dayOfWeek: 7, intervals: [{ from: '09:00', to: '15:00' }] },
    ],
    maxVehicleWeightTons: 10,
    noShowGraceMinutes: 30,
    leadTimeMinutes: 60,
    horizonDays: 14,
  };
}

export function intervalsForDate(
  config: StoreConfig,
  dateKey: string,
): readonly { from: string; to: string }[] {
  const dow = isoDayOfWeek(dateKey);
  return (
    config.receivingWindows.find((w) => w.dayOfWeek === dow)?.intervals ?? []
  );
}

/** Усі можливі старти слотів дати (хвилини від початку київської доби). */
export function slotStartsForDate(
  config: StoreConfig,
  dateKey: string,
): number[] {
  const result: number[] = [];
  for (const interval of intervalsForDate(config, dateKey)) {
    const from = parseHhMm(interval.from);
    const to = parseHhMm(interval.to);
    for (let m = from; m + config.slotSizeMinutes <= to; m += config.slotSizeMinutes) {
      result.push(m);
    }
  }
  return result;
}

function plate(rng: () => number): string {
  const digits = String(1000 + Math.floor(rng() * 9000));
  const prefix = PLATE_PREFIXES[Math.floor(rng() * PLATE_PREFIXES.length)];
  const suffix = PLATE_SUFFIXES[Math.floor(rng() * PLATE_SUFFIXES.length)];
  return `${prefix}${digits}${suffix}`;
}

function change(
  from: string | null,
  to: string,
  at: string,
  by: string,
  meta?: Record<string, unknown>,
): WireStatusChange {
  return meta ? { from, to, at, by, meta } : { from, to, at, by };
}

/** HH:mm у Києві для поля `localTime`. */
function localTime(iso: string): string {
  return new Intl.DateTimeFormat('uk-UA', {
    timeZone: 'Europe/Kyiv',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(iso));
}

const REJECT_REASON_POOL: readonly string[] = [
  'відсутні документи',
  'невідповідність вантажу',
  'перевищення тоннажу',
];
const DELAY_REASON_POOL: readonly string[] = [
  'затори',
  'поломка',
  'затримка на попередній точці',
];

/**
 * Генерує реалістичний день магазину: минулі слоти закриті, поточний —
 * у розвантаженні, майбутні — заплановані; є overrun, walk-in, затримка,
 * no_show та відмова.
 */
export function generateDay(
  store: MockStore,
  config: StoreConfig,
  dateKey: string,
  nowIso: string,
): WireBooking[] {
  const rng = makeRng(`day:${config.storeId}:${dateKey}`);
  const nowMs = new Date(nowIso).getTime();
  const todayKey = toKyivDateKey(nowIso);
  const isPast = dateKey < todayKey;
  const isFuture = dateKey > todayKey;
  const starts = slotStartsForDate(config, dateKey);
  const generated: WireBooking[] = [];
  const snapshot: WireStoreSnapshot = {
    externalId: store.externalId,
    displayName: store.displayName,
    city: store.city,
    address: store.address,
  };

  let seq = 0;
  for (const ramp of config.ramps) {
    for (const startMinutes of starts) {
      if (rng() > 0.5) continue;
      seq += 1;
      const slotStart = kyivToUtcIso(dateKey, startMinutes);
      const slotEnd = kyivToUtcIso(dateKey, startMinutes + config.slotSizeMinutes);
      const slotStartMs = new Date(slotStart).getTime();
      const slotEndMs = new Date(slotEnd).getTime();

      const supplier = SUPPLIERS[Math.floor(rng() * SUPPLIERS.length)];
      const driverId =
        rng() > 0.2 ? DRIVER_IDS[Math.floor(rng() * DRIVER_IDS.length)] : null;
      const pallets = 4 + Math.floor(rng() * 22);
      const id = `bk-${store.externalId}-${dateKey}-${ramp.rampId}-${startMinutes}`;

      let status: string;
      if (isFuture) {
        status = 'booked';
      } else if (isPast) {
        const roll = rng();
        status = roll < 0.82 ? 'completed' : roll < 0.92 ? 'no_show' : 'rejected';
      } else if (slotEndMs <= nowMs) {
        const roll = rng();
        status = roll < 0.8 ? 'completed' : roll < 0.9 ? 'no_show' : 'rejected';
      } else if (slotStartMs <= nowMs) {
        status = rng() < 0.6 ? 'unloading' : 'arrived';
      } else if (slotStartMs - nowMs < 45 * 60_000 && rng() < 0.5) {
        status = 'arrived';
      } else {
        status = 'booked';
      }

      const createdAt = new Date(slotStartMs - 26 * 3600_000).toISOString();
      const statusHistory: WireStatusChange[] = [];

      let arrivedAt: string | null = null;
      let unloadingStartedAt: string | null = null;
      let completedAt: string | null = null;
      let unloadedPalletsCount: number | null = null;
      let partialUnload: WireBooking['partialUnload'] = null;
      let rejectedAt: WireBooking['rejectedAt'] = null;

      if (
        status === 'arrived' ||
        status === 'unloading' ||
        status === 'completed' ||
        status === 'rejected'
      ) {
        arrivedAt = new Date(
          slotStartMs - Math.floor(rng() * 20) * 60_000,
        ).toISOString();
        statusHistory.push(
          change('booked', 'arrived', arrivedAt, driverId ?? 'u-operator'),
        );
      }
      if (status === 'unloading' || status === 'completed') {
        unloadingStartedAt = new Date(
          Math.max(slotStartMs, new Date(arrivedAt as string).getTime()) +
            Math.floor(rng() * 12) * 60_000,
        ).toISOString();
        statusHistory.push(
          change('arrived', 'unloading', unloadingStartedAt, 'u-operator'),
        );
      }
      if (status === 'completed') {
        const duration = 18 + Math.floor(rng() * 30);
        completedAt = new Date(
          new Date(unloadingStartedAt as string).getTime() + duration * 60_000,
        ).toISOString();
        const partial = rng() < 0.18;
        unloadedPalletsCount = partial
          ? Math.max(1, pallets - 1 - Math.floor(rng() * 5))
          : pallets;
        partialUnload = partial
          ? {
              flag: true,
              reason: 'розбіжність із замовленням',
              comment: null,
            }
          : null;
        statusHistory.push(
          change('unloading', 'completed', completedAt, 'u-operator', {
            unloadedPalletsCount,
          }),
        );
      }
      if (status === 'rejected') {
        const reason =
          REJECT_REASON_POOL[Math.floor(rng() * REJECT_REASON_POOL.length)];
        const at = new Date(
          new Date(arrivedAt as string).getTime() + 9 * 60_000,
        ).toISOString();
        rejectedAt = { at, by: 'u-operator', reason, comment: null };
        statusHistory.push(
          change('arrived', 'rejected', at, 'u-operator', { reason }),
        );
      }
      if (status === 'no_show') {
        const at = new Date(
          slotEndMs + config.noShowGraceMinutes * 60_000,
        ).toISOString();
        statusHistory.push(
          change('booked', 'no_show', at, 'system', { auto: true }),
        );
      }

      const delayed =
        status === 'booked' && slotStartMs > nowMs && rng() < 0.12
          ? {
              flag: true,
              reason:
                DELAY_REASON_POOL[Math.floor(rng() * DELAY_REASON_POOL.length)],
              eta: new Date(slotStartMs + 40 * 60_000).toISOString(),
            }
          : { flag: false, reason: null, eta: null };

      generated.push({
        id,
        type: 'scheduled',
        status,
        storeId: store.storeId,
        store: snapshot,
        rampId: ramp.rampId,
        slotStart,
        slotEnd,
        localDate: dateKey,
        localTime: localTime(slotStart),
        supplierId: supplier.supplierId,
        supplierName: supplier.name,
        vehicle: {
          plateNumber: plate(rng),
          weightTons: [3.5, 5, 7.5, 10][Math.floor(rng() * 4)],
          brand: BRANDS[Math.floor(rng() * BRANDS.length)],
        },
        driverId,
        orderId: rng() < 0.85 ? `ORD-${10000 + seq * 37}` : null,
        palletsCount: pallets,
        delayed,
        arrivedAt,
        unloadingStartedAt,
        completedAt,
        cancelledAt: null,
        cancellation: null,
        rejectedAt,
        unloadedPalletsCount,
        partialUnload,
        rescheduleOf: null,
        routeSheetId: null,
        createdBy: supplier.supplierId,
        createdAt,
        updatedAt: completedAt ?? unloadingStartedAt ?? arrivedAt ?? slotStart,
        statusHistory,
      });
    }
  }

  // Гарантований overrun на першій рампі поточної дати (STW-40).
  if (!isPast && !isFuture) {
    const index = lastIndexMatching(
      generated,
      (b) =>
        b.rampId === config.ramps[0].rampId &&
        new Date(b.slotEnd).getTime() < nowMs,
    );
    if (index >= 0) {
      const target = generated[index];
      const slotStartMs = new Date(target.slotStart).getTime();
      const arrived = new Date(slotStartMs - 12 * 60_000).toISOString();
      const started = new Date(slotStartMs + 6 * 60_000).toISOString();
      generated[index] = {
        ...target,
        status: 'unloading',
        arrivedAt: arrived,
        unloadingStartedAt: started,
        completedAt: null,
        unloadedPalletsCount: null,
        partialUnload: null,
        rejectedAt: null,
        updatedAt: started,
        statusHistory: [
          change('booked', 'arrived', arrived, target.driverId ?? 'u-operator'),
          change('arrived', 'unloading', started, 'u-operator'),
        ],
      };
    }
  }

  // Вчорашнє незакрите розвантаження — сценарій дозакриття зміни (STW-22).
  if (isPast && generated.length) {
    const index = generated.length - 1;
    const target = generated[index];
    const slotStartMs = new Date(target.slotStart).getTime();
    const arrived = new Date(slotStartMs - 10 * 60_000).toISOString();
    const started = new Date(slotStartMs + 5 * 60_000).toISOString();
    generated[index] = {
      ...target,
      status: 'unloading',
      arrivedAt: arrived,
      unloadingStartedAt: started,
      completedAt: null,
      unloadedPalletsCount: null,
      partialUnload: null,
      rejectedAt: null,
      updatedAt: started,
      statusHistory: [
        change('booked', 'arrived', arrived, target.driverId ?? 'u-operator'),
        change('arrived', 'unloading', started, 'u-operator'),
      ],
    };
  }

  // Позапланові прибуття поточної дати (STW-37).
  if (!isPast && !isFuture) {
    generated.push(...buildWalkIns(store, config, dateKey, nowIso, snapshot));
  }

  return generated;
}

function lastIndexMatching(
  list: readonly WireBooking[],
  predicate: (booking: WireBooking) => boolean,
): number {
  let best = -1;
  let bestStart = Number.NEGATIVE_INFINITY;
  list.forEach((booking, index) => {
    if (!predicate(booking)) return;
    const start = new Date(booking.slotStart).getTime();
    if (start > bestStart) {
      bestStart = start;
      best = index;
    }
  });
  return best;
}

function buildWalkIns(
  store: MockStore,
  config: StoreConfig,
  dateKey: string,
  nowIso: string,
  snapshot: WireStoreSnapshot,
): WireBooking[] {
  const rng = makeRng(`walkin:${config.storeId}:${dateKey}`);
  const nowMs = new Date(nowIso).getTime();
  const lastRamp = config.ramps[config.ramps.length - 1];
  const result: WireBooking[] = [];

  const specs = [
    {
      offsetMinutes: -60,
      supplierId: null,
      name: 'ФОП Гуменюк В. П. (поза системою)',
      status: 'completed',
    },
    {
      offsetMinutes: -15,
      supplierId: SUPPLIERS[3].supplierId,
      name: SUPPLIERS[3].name,
      status: 'arrived',
    },
  ];

  specs.forEach((spec, index) => {
    const slotStartMs =
      Math.floor((nowMs + spec.offsetMinutes * 60_000) / (30 * 60_000)) *
      (30 * 60_000);
    const slotStart = new Date(slotStartMs).toISOString();
    const slotEnd = new Date(
      slotStartMs + config.slotSizeMinutes * 60_000,
    ).toISOString();
    const id = `wi-${store.externalId}-${dateKey}-${index}`;
    const pallets = 3 + Math.floor(rng() * 8);
    const arrivedAt = slotStart;
    const statusHistory: WireStatusChange[] = [
      change(null, 'arrived', arrivedAt, 'u-operator', { walkIn: true }),
    ];
    let unloadingStartedAt: string | null = null;
    let completedAt: string | null = null;
    let unloadedPalletsCount: number | null = null;

    if (spec.status === 'completed') {
      unloadingStartedAt = new Date(slotStartMs + 7 * 60_000).toISOString();
      completedAt = new Date(slotStartMs + 32 * 60_000).toISOString();
      unloadedPalletsCount = pallets;
      statusHistory.push(
        change('arrived', 'unloading', unloadingStartedAt, 'u-operator'),
        change('unloading', 'completed', completedAt, 'u-operator', {
          unloadedPalletsCount,
        }),
      );
    }

    result.push({
      id,
      type: 'walk_in',
      status: spec.status,
      storeId: store.storeId,
      store: snapshot,
      rampId: lastRamp.rampId,
      slotStart,
      slotEnd,
      localDate: dateKey,
      localTime: localTime(slotStart),
      supplierId: spec.supplierId,
      supplierName: spec.name,
      vehicle: { plateNumber: plate(rng), weightTons: 5, brand: null },
      driverId: null,
      orderId: null,
      palletsCount: pallets,
      delayed: { flag: false, reason: null, eta: null },
      arrivedAt,
      unloadingStartedAt,
      completedAt,
      cancelledAt: null,
      cancellation: null,
      rejectedAt: null,
      unloadedPalletsCount,
      partialUnload: null,
      rescheduleOf: null,
      routeSheetId: null,
      createdBy: 'u-operator',
      createdAt: arrivedAt,
      updatedAt: completedAt ?? arrivedAt,
      statusHistory,
    });
  });

  return result;
}

export function roleLabelKey(role: StaffRole): string {
  return `header.role.${role}`;
}
