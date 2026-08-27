import {
  AuditEntry,
  Booking,
  BookingStatus,
  DelayReason,
  DriverInfo,
  RejectReason,
} from '../models/booking.model';
import { StaffProfile, StaffRole, StoreScope } from '../models/auth.model';
import { Ramp, StoreConfig, SupplierRef } from '../models/store.model';
import {
  isoDayOfWeek,
  kyivToUtcIso,
  parseHhMm,
  toKyivDateKey,
} from '../util/date.util';
import { BRANCHES } from './branches.fixture';

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

const DRIVERS: readonly DriverInfo[] = [
  { driverId: 'dr-01', fullName: 'Іван Коваленко', phone: '+380671234501' },
  { driverId: 'dr-02', fullName: 'Петро Мельник', phone: '+380671234502' },
  { driverId: 'dr-03', fullName: 'Олег Шевченко', phone: '+380671234503' },
  { driverId: 'dr-04', fullName: 'Андрій Бондаренко', phone: '+380671234504' },
  { driverId: 'dr-05', fullName: 'Сергій Ткаченко', phone: '+380671234505' },
  { driverId: 'dr-06', fullName: 'Микола Гриценко', phone: '+380671234506' },
];

const PLATE_PREFIXES = ['AA', 'AI', 'AX', 'BC', 'CA', 'KA'];
const PLATE_SUFFIXES = ['BB', 'IP', 'KX', 'MM', 'OP', 'TT'];
const BRANDS = ['MAN', 'Volvo', 'Scania', 'DAF', 'Mercedes-Benz', 'Renault'];

function branchToScope(index: number): StoreScope {
  const branch = BRANCHES[index % BRANCHES.length];
  return {
    storeId: branch.branchId,
    externalId: branch.externalId,
    displayName: `Сільпо №${branch.externalId}`,
    city: branch.city,
    address: branch.address,
  };
}

export function storeScopes(count: number, offset = 0): StoreScope[] {
  return Array.from({ length: count }, (_, i) => branchToScope(offset + i));
}

/** Демо-користувачі мок-режиму. */
export const MOCK_USERS: readonly StaffProfile[] = [
  {
    userId: 'u-operator',
    fullName: 'Оксана Литвин',
    email: 'operator@silpo.ua',
    role: 'store_operator',
    stores: storeScopes(2, 0),
  },
  {
    userId: 'u-manager',
    fullName: 'Дмитро Савченко',
    email: 'manager@silpo.ua',
    role: 'store_manager',
    stores: storeScopes(3, 2),
  },
  {
    userId: 'u-single',
    fullName: 'Ірина Панченко',
    email: 'single@silpo.ua',
    role: 'store_operator',
    stores: storeScopes(1, 5),
  },
  {
    userId: 'u-outsider',
    fullName: 'Тарас Гнатюк',
    email: 'admin@silpo.ua',
    role: 'admin',
    stores: [],
  },
];

export function buildStoreConfig(scope: StoreScope): StoreConfig {
  const rng = makeRng(`config:${scope.storeId}`);
  const rampCount = 3 + Math.floor(rng() * 2);
  return {
    storeId: scope.storeId,
    externalId: scope.externalId,
    displayName: scope.displayName,
    city: scope.city,
    address: scope.address,
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

interface GeneratedBooking {
  booking: Booking;
  audit: AuditEntry[];
}

function plate(rng: () => number): string {
  const digits = String(1000 + Math.floor(rng() * 9000));
  const prefix = PLATE_PREFIXES[Math.floor(rng() * PLATE_PREFIXES.length)];
  const suffix = PLATE_SUFFIXES[Math.floor(rng() * PLATE_SUFFIXES.length)];
  return `${prefix}${digits}${suffix}`;
}

function auditEntry(
  bookingId: string,
  at: string,
  action: AuditEntry['action'],
  fromValue: string | null,
  toValue: string | null,
  actorKind: AuditEntry['actorKind'] = 'staff',
  actorName = 'Оксана Литвин',
  actorRole: string | null = 'store_operator',
  comment: string | null = null,
): AuditEntry {
  return {
    id: `au-${bookingId}-${action}-${at}`,
    bookingId,
    at,
    actorKind,
    actorName,
    actorRole,
    action,
    fromValue,
    toValue,
    comment,
  };
}

const REJECT_REASON_POOL: readonly RejectReason[] = [
  'missing_documents',
  'cargo_mismatch',
  'weight_exceeded',
];
const DELAY_REASON_POOL: readonly DelayReason[] = [
  'ramp_busy',
  'previous_vehicle',
  'technical',
];

/**
 * Генерує реалістичний день магазину: минулі слоти закриті, поточний —
 * у розвантаженні, майбутні — заплановані; є overrun, walk-in, затримка,
 * no_show та відмова.
 */
export function generateDay(
  config: StoreConfig,
  dateKey: string,
  nowIso: string,
): { bookings: Booking[]; audit: AuditEntry[] } {
  const rng = makeRng(`day:${config.storeId}:${dateKey}`);
  const nowMs = new Date(nowIso).getTime();
  const todayKey = toKyivDateKey(nowIso);
  const isPast = dateKey < todayKey;
  const isFuture = dateKey > todayKey;
  const starts = slotStartsForDate(config, dateKey);
  const generated: GeneratedBooking[] = [];

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
      const driver =
        rng() > 0.2 ? DRIVERS[Math.floor(rng() * DRIVERS.length)] : null;
      const pallets = 4 + Math.floor(rng() * 22);
      const id = `bk-${config.externalId}-${dateKey}-${ramp.rampId}-${startMinutes}`;

      let status: BookingStatus;
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

      const audit: AuditEntry[] = [];
      const createdAt = new Date(slotStartMs - 26 * 3600_000).toISOString();
      audit.push(
        auditEntry(
          id,
          createdAt,
          'created',
          null,
          'booked',
          'supplier',
          supplier.name,
          null,
        ),
      );

      let arrivedAt: string | null = null;
      let unloadingStartedAt: string | null = null;
      let completedAt: string | null = null;
      let unloadedPalletsCount: number | null = null;
      let partialUnload: Booking['partialUnload'] = null;
      let rejectedAt: Booking['rejectedAt'] = null;

      if (
        status === 'arrived' ||
        status === 'unloading' ||
        status === 'completed' ||
        status === 'rejected'
      ) {
        arrivedAt = new Date(
          slotStartMs - Math.floor(rng() * 20) * 60_000,
        ).toISOString();
        audit.push(
          auditEntry(
            id,
            arrivedAt,
            'status_changed',
            'booked',
            'arrived',
            'driver',
            driver?.fullName ?? 'Водій',
            null,
          ),
        );
      }
      if (status === 'unloading' || status === 'completed') {
        unloadingStartedAt = new Date(
          Math.max(slotStartMs, new Date(arrivedAt as string).getTime()) +
            Math.floor(rng() * 12) * 60_000,
        ).toISOString();
        audit.push(
          auditEntry(id, unloadingStartedAt, 'status_changed', 'arrived', 'unloading'),
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
          ? { flag: true, reason: 'order_mismatch', comment: null }
          : null;
        audit.push(
          auditEntry(id, completedAt, 'status_changed', 'unloading', 'completed'),
        );
        audit.push(
          auditEntry(
            id,
            completedAt,
            'unload_recorded',
            String(pallets),
            String(unloadedPalletsCount),
          ),
        );
      }
      if (status === 'rejected') {
        const reason = REJECT_REASON_POOL[Math.floor(rng() * REJECT_REASON_POOL.length)];
        const at = new Date(
          new Date(arrivedAt as string).getTime() + 9 * 60_000,
        ).toISOString();
        rejectedAt = { at, by: 'u-operator', reason, comment: null };
        audit.push(auditEntry(id, at, 'rejected', 'arrived', 'rejected'));
      }
      if (status === 'no_show') {
        const at = new Date(slotEndMs + config.noShowGraceMinutes * 60_000).toISOString();
        audit.push(
          auditEntry(
            id,
            at,
            'status_changed',
            'booked',
            'no_show',
            'system_cron',
            'system-cron',
            null,
          ),
        );
      }

      const delayed =
        status === 'booked' && slotStartMs > nowMs && rng() < 0.12
          ? {
              flag: true,
              reason: DELAY_REASON_POOL[Math.floor(rng() * DELAY_REASON_POOL.length)],
              eta: new Date(slotStartMs + 40 * 60_000).toISOString(),
              comment: null,
            }
          : { flag: false, reason: null, eta: null, comment: null };
      if (delayed.flag) {
        audit.push(
          auditEntry(
            id,
            new Date(slotStartMs - 90 * 60_000).toISOString(),
            'delay_set',
            null,
            delayed.eta,
          ),
        );
      }

      const booking: Booking = {
        id,
        type: 'scheduled',
        storeId: config.storeId,
        rampId: ramp.rampId,
        slotStart,
        slotEnd,
        supplierId: supplier.supplierId,
        supplierNameSnapshot: supplier.name,
        vehicle: {
          plateNumber: plate(rng),
          weightTons: [3.5, 5, 7.5, 10][Math.floor(rng() * 4)],
          brand: BRANDS[Math.floor(rng() * BRANDS.length)],
        },
        driver,
        orderId: rng() < 0.85 ? `ORD-${10000 + seq * 37}` : null,
        palletsCount: pallets,
        status,
        delayed,
        arrivedAt,
        unloadingStartedAt,
        completedAt,
        cancelledAt: null,
        rejectedAt,
        unloadedPalletsCount,
        partialUnload,
        version: 1,
        updatedAt: completedAt ?? unloadingStartedAt ?? arrivedAt ?? slotStart,
      };
      generated.push({ booking, audit });
    }
  }

  // Гарантований overrun на першій рампі поточної дати (STW-40).
  if (!isPast && !isFuture) {
    const candidates = generated
      .filter(
        (g) =>
          g.booking.rampId === config.ramps[0].rampId &&
          new Date(g.booking.slotEnd).getTime() < nowMs,
      )
      .sort(
        (a, b) =>
          new Date(b.booking.slotStart).getTime() -
          new Date(a.booking.slotStart).getTime(),
      );
    const target = candidates[0];
    if (target) {
      const slotStartMs = new Date(target.booking.slotStart).getTime();
      const arrived = new Date(slotStartMs - 12 * 60_000).toISOString();
      const started = new Date(slotStartMs + 6 * 60_000).toISOString();
      target.booking = {
        ...target.booking,
        status: 'unloading',
        arrivedAt: arrived,
        unloadingStartedAt: started,
        completedAt: null,
        unloadedPalletsCount: null,
        partialUnload: null,
        rejectedAt: null,
        updatedAt: started,
      };
      target.audit = [
        target.audit[0],
        auditEntry(
          target.booking.id,
          arrived,
          'status_changed',
          'booked',
          'arrived',
          'driver',
          target.booking.driver?.fullName ?? 'Водій',
          null,
        ),
        auditEntry(target.booking.id, started, 'status_changed', 'arrived', 'unloading'),
      ];
    }
  }

  // Вчорашнє незакрите розвантаження — сценарій дозакриття зміни (STW-22).
  if (isPast) {
    const target = generated[generated.length - 1];
    if (target) {
      const slotStartMs = new Date(target.booking.slotStart).getTime();
      target.booking = {
        ...target.booking,
        status: 'unloading',
        arrivedAt: new Date(slotStartMs - 10 * 60_000).toISOString(),
        unloadingStartedAt: new Date(slotStartMs + 5 * 60_000).toISOString(),
        completedAt: null,
        unloadedPalletsCount: null,
        partialUnload: null,
        rejectedAt: null,
      };
    }
  }

  // Позапланові прибуття поточної дати (STW-37).
  if (!isPast && !isFuture) {
    const walkIns = buildWalkIns(config, dateKey, nowIso);
    generated.push(...walkIns);
  }

  return {
    bookings: generated.map((g) => g.booking),
    audit: generated.flatMap((g) => g.audit),
  };
}

function buildWalkIns(
  config: StoreConfig,
  dateKey: string,
  nowIso: string,
): GeneratedBooking[] {
  const rng = makeRng(`walkin:${config.storeId}:${dateKey}`);
  const nowMs = new Date(nowIso).getTime();
  const lastRamp = config.ramps[config.ramps.length - 1];
  const result: GeneratedBooking[] = [];

  const specs = [
    {
      offsetMinutes: -60,
      supplierId: null,
      name: 'ФОП Гуменюк В. П. (поза системою)',
      status: 'completed' as BookingStatus,
    },
    {
      offsetMinutes: -15,
      supplierId: SUPPLIERS[3].supplierId,
      name: SUPPLIERS[3].name,
      status: 'arrived' as BookingStatus,
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
    const id = `wi-${config.externalId}-${dateKey}-${index}`;
    const pallets = 3 + Math.floor(rng() * 8);
    const arrivedAt = slotStart;
    const audit: AuditEntry[] = [
      auditEntry(id, arrivedAt, 'created', null, 'arrived'),
    ];
    let unloadingStartedAt: string | null = null;
    let completedAt: string | null = null;
    let unloadedPalletsCount: number | null = null;

    if (spec.status === 'completed') {
      unloadingStartedAt = new Date(slotStartMs + 7 * 60_000).toISOString();
      completedAt = new Date(slotStartMs + 32 * 60_000).toISOString();
      unloadedPalletsCount = pallets;
      audit.push(
        auditEntry(id, unloadingStartedAt, 'status_changed', 'arrived', 'unloading'),
        auditEntry(id, completedAt, 'status_changed', 'unloading', 'completed'),
      );
    }

    result.push({
      booking: {
        id,
        type: 'walk_in',
        storeId: config.storeId,
        rampId: lastRamp.rampId,
        slotStart,
        slotEnd,
        supplierId: spec.supplierId,
        supplierNameSnapshot: spec.name,
        vehicle: {
          plateNumber: plate(rng),
          weightTons: 5,
          brand: null,
        },
        driver: null,
        orderId: null,
        palletsCount: pallets,
        status: spec.status,
        delayed: { flag: false, reason: null, eta: null, comment: null },
        arrivedAt,
        unloadingStartedAt,
        completedAt,
        cancelledAt: null,
        rejectedAt: null,
        unloadedPalletsCount,
        partialUnload: null,
        version: 1,
        updatedAt: completedAt ?? arrivedAt,
      },
      audit,
    });
  });

  return result;
}

export function roleLabelKey(role: StaffRole): string {
  return `header.role.${role}`;
}
