import { Injectable } from '@angular/core';
import {
  AuditEntry,
  Booking,
  BookingStatus,
  CalendarException,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotBlock,
  SlotSizeMinutes,
  StaffUser,
  Store,
  Supplier,
  SupplierDriver,
  SupplierUser,
  SyncRun,
  Vehicle,
  YmsStatus,
} from '../../models';
import { MCP_BRANCHES } from '../../fixtures/mcp.fixture';
import { addDays, kyivDate } from '../../utils/time.util';
import {
  emptyReceivingWindows,
  isStoreConfigured,
} from '../../utils/store-config.util';

type Mutable<T> = { -readonly [K in keyof T]: T[K] };

/** Детермінований генератор — дані моків стабільні між запусками і в тестах. */
export function createRandom(seed: number): () => number {
  let state = seed >>> 0 || 1;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

const SUPPLIER_SEED: ReadonlyArray<{
  name: string;
  edrpou: string;
  person: string;
  status: 'active' | 'suspended';
}> = [
  { name: 'ТОВ «Молочний Дім»', edrpou: '32145678', person: 'Олена Кравчук', status: 'active' },
  { name: 'ПрАТ «Хлібодар»', edrpou: '21456789', person: 'Ігор Мельник', status: 'active' },
  { name: 'ТОВ «Фрешлайн Логістик»', edrpou: '4312567890', person: 'Марія Бондар', status: 'active' },
  { name: 'ТОВ «Овочі Поділля»', edrpou: '38765412', person: 'Андрій Ткаченко', status: 'active' },
  { name: 'ТОВ «Мʼясний Стандарт»', edrpou: '30987654', person: 'Сергій Гнатюк', status: 'active' },
  { name: 'ПП «Напої Карпат»', edrpou: '2233445566', person: 'Наталія Гриценко', status: 'suspended' },
  { name: 'ТОВ «Бакалія Плюс»', edrpou: '35112233', person: 'Дмитро Савченко', status: 'active' },
  { name: 'ТОВ «Кондитер Львів»', edrpou: '36445566', person: 'Ірина Дацюк', status: 'active' },
  { name: 'ТОВ «Риба Одеси»', edrpou: '37556677', person: 'Владислав Кучер', status: 'suspended' },
  { name: 'ТОВ «Заморозка Схід»', edrpou: '3866778899', person: 'Тетяна Литвин', status: 'active' },
  { name: 'ТОВ «ЕкоФерма Волинь»', edrpou: '39887766', person: 'Роман Панасюк', status: 'active' },
  { name: 'ТОВ «Побутхім Дистрибуція»', edrpou: '31229988', person: 'Юлія Романенко', status: 'active' },
];

const STAFF_SEED: ReadonlyArray<Omit<StaffUser, 'storeIds'> & { storeIndexes: number[] }> = [
  {
    id: 'su-1',
    fullName: 'Руслан Мамедов',
    email: 'super.admin@silpo.ua',
    phone: '+380671110001',
    role: 'super_admin',
    active: true,
    storeIndexes: [],
  },
  {
    id: 'su-2',
    fullName: 'Оксана Лисенко',
    email: 'network.manager@silpo.ua',
    phone: '+380671110002',
    role: 'network_manager',
    active: true,
    storeIndexes: [],
  },
  {
    id: 'su-3',
    fullName: 'Павло Гончар',
    email: 'store.manager@silpo.ua',
    phone: '+380671110003',
    role: 'store_manager',
    active: true,
    storeIndexes: [0, 1, 2],
  },
  {
    id: 'su-4',
    fullName: 'Вікторія Шевчук',
    email: 'analyst@silpo.ua',
    phone: '+380671110004',
    role: 'analyst',
    active: true,
    storeIndexes: [],
  },
  {
    id: 'su-5',
    fullName: 'Микола Дяченко',
    email: 'store.operator@silpo.ua',
    phone: '+380671110005',
    role: 'store_operator',
    active: true,
    storeIndexes: [3, 4],
  },
  {
    id: 'su-6',
    fullName: 'Аліна Терещенко',
    email: 'alina.tereshchenko@silpo.ua',
    phone: '+380671110006',
    role: 'store_manager',
    active: false,
    storeIndexes: [5],
  },
];

const DELAY_REASONS = [
  'Затримка на попередній точці',
  'Дорожній затор',
  'Технічна несправність авто',
  'Пізнє завантаження на складі',
];

const BLOCK_REASONS = [
  'Інвентаризація',
  'Ремонт рампи',
  'Планове відключення електроенергії',
];

function standardWindows(): ReceivingWindow[] {
  return emptyReceivingWindows().map((w) =>
    w.dayOfWeek === 7
      ? w
      : { dayOfWeek: w.dayOfWeek, intervals: [{ from: '08:00', to: '18:00' }] },
  );
}

function splitWindows(): ReceivingWindow[] {
  return emptyReceivingWindows().map((w) =>
    w.dayOfWeek >= 6
      ? w.dayOfWeek === 6
        ? { dayOfWeek: w.dayOfWeek, intervals: [{ from: '09:00', to: '14:00' }] }
        : w
      : {
          dayOfWeek: w.dayOfWeek,
          intervals: [
            { from: '07:00', to: '12:00' },
            { from: '13:00', to: '19:00' },
          ],
        },
  );
}

function makeRamps(count: number, storeId: string, withBookings: number): Ramp[] {
  return Array.from({ length: count }, (_, i) => ({
    id: `${storeId}-ramp-${i + 1}`,
    number: i + 1,
    name: i === 0 ? 'Основна' : `Рампа ${i + 1}`,
    enabled: true,
    disabledFrom: null,
    hasBookings: i < withBookings,
  }));
}

export interface MockState {
  stores: Store[];
  suppliers: Supplier[];
  supplierUsers: SupplierUser[];
  vehicles: Vehicle[];
  drivers: SupplierDriver[];
  staff: StaffUser[];
  syncRuns: SyncRun[];
  bookings: Booking[];
  audit: AuditEntry[];
  syncRunning: boolean;
}

/**
 * InMemory-джерело даних для роботи без бекенду (environment.useMocks).
 * Дані філій і міст походять з fixtures/silpo-branches.json та cities.json.
 */
@Injectable({ providedIn: 'root' })
export class MockDb {
  readonly state: MockState = seed();

  reset(): void {
    const fresh = seed();
    this.state.stores = fresh.stores;
    this.state.suppliers = fresh.suppliers;
    this.state.supplierUsers = fresh.supplierUsers;
    this.state.vehicles = fresh.vehicles;
    this.state.drivers = fresh.drivers;
    this.state.staff = fresh.staff;
    this.state.syncRuns = fresh.syncRuns;
    this.state.bookings = fresh.bookings;
    this.state.audit = fresh.audit;
    this.state.syncRunning = false;
  }

  nextId(prefix: string): string {
    return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
  }
}

export function seed(): MockState {
  const rnd = createRandom(20260827);
  const today = kyivDate();

  const suppliers: Mutable<Supplier>[] = SUPPLIER_SEED.map((s, i) => ({
    id: `sup-${i + 1}`,
    name: s.name,
    edrpou: s.edrpou,
    contactPerson: s.person,
    contactPhone: `+38067${String(2000000 + i * 13457).slice(0, 7)}`,
    contactEmail: `contact${i + 1}@postachalnyk.ua`,
    status: s.status,
    storeAccessMode: i % 3 === 0 ? 'whitelist' : 'all',
    allowedStoreIds: [],
    bookingsCount: 0,
  }));

  const stores: Store[] = MCP_BRANCHES.map((branch, index) => {
    const id = `st-${branch.externalId}`;
    const bucket = index % 10;
    let status: YmsStatus;
    if (bucket <= 4) status = 'active';
    else if (bucket === 5) status = 'paused';
    else if (bucket === 9) status = 'archived';
    else status = 'not_configured';

    const configured = status === 'active' || status === 'paused';
    const rampCount = configured ? 1 + (index % 4) : 0;
    const ramps = makeRamps(rampCount, id, configured ? 1 : 0);
    const slotSize: SlotSizeMinutes | null = configured
      ? ([30, 60, 20, 15] as SlotSizeMinutes[])[index % 4]
      : null;
    const windows = configured
      ? index % 3 === 0
        ? splitWindows()
        : standardWindows()
      : emptyReceivingWindows();
    const maxWeight = configured ? 5 + Math.round(rnd() * 60) / 2 : null;

    const exceptions: CalendarException[] = configured && index % 7 === 0
      ? [
          {
            id: `${id}-exc-1`,
            date: addDays(today, 14),
            type: 'closed',
            intervals: [],
            reason: 'Інвентаризація',
          },
          {
            id: `${id}-exc-2`,
            date: addDays(today, 21),
            type: 'custom',
            intervals: [{ from: '09:00', to: '13:00' }],
            reason: 'Скорочений день перед святом',
          },
        ]
      : [];

    const reservedRules: ReservedSlotRule[] =
      configured && index % 5 === 0 && ramps.length > 0
        ? [
            {
              id: `${id}-res-1`,
              supplierId: suppliers[index % suppliers.length].id,
              dayOfWeek: 2,
              date: null,
              slotStartTime: '08:00',
              rampId: ramps[0].id,
              validFrom: addDays(today, -30),
              validTo: null,
              active: true,
            },
          ]
        : [];

    const slotBlocks: SlotBlock[] =
      configured && index % 11 === 0 && ramps.length > 0
        ? [
            {
              id: `${id}-blk-1`,
              date: addDays(today, 3),
              from: '12:00',
              to: '15:00',
              rampIds: [ramps[0].id],
              reason: BLOCK_REASONS[index % BLOCK_REASONS.length],
              active: true,
              createdAt: new Date().toISOString(),
            },
          ]
        : [];

    const store: Store = {
      ...branch,
      id,
      displayName: `Сільпо ${branch.externalId} — ${branch.address}`,
      phone: index % 4 === 0 ? null : `+38044${String(3000000 + index * 719).slice(0, 7)}`,
      addressOverride: index % 13 === 0 ? `${branch.address} (вʼїзд з двору)` : null,
      ymsStatus: status,
      visibleToSuppliers: status === 'active',
      slotSizeMinutes: slotSize,
      ramps,
      maxVehicleWeightTons: maxWeight === null ? null : clampWeight(maxWeight),
      leadTimeHours: configured ? [2, 4, 12, 24][index % 4] : 4,
      bookingHorizonDays: configured ? [14, 21, 30][index % 3] : 14,
      receivingWindows: windows,
      exceptions,
      reservedRules,
      slotBlocks,
      isConfigured: false,
      lastSyncedAt: new Date(Date.now() - (index % 12) * 3_600_000).toISOString(),
      missingSyncCount: 0,
    };
    return { ...store, isConfigured: isStoreConfigured(store) };
  });

  // whitelist-постачальникам призначаємо кілька активних магазинів
  const activeStoreIds = stores.filter((s) => s.ymsStatus === 'active').map((s) => s.id);
  suppliers.forEach((supplier, i) => {
    if (supplier.storeAccessMode === 'whitelist') {
      supplier.allowedStoreIds = activeStoreIds.slice(i * 3, i * 3 + 6);
    }
  });

  const supplierUsers: SupplierUser[] = suppliers.flatMap((supplier, i) => [
    {
      id: `supu-${i + 1}-a`,
      supplierId: supplier.id,
      fullName: supplier.contactPerson,
      email: `admin${i + 1}@postachalnyk.ua`,
      phone: supplier.contactPhone,
      role: 'supplier_admin' as const,
      active: true,
    },
    {
      id: `supu-${i + 1}-o`,
      supplierId: supplier.id,
      fullName: `Оператор ${i + 1}`,
      email: `operator${i + 1}@postachalnyk.ua`,
      phone: `+38050${String(1000000 + i * 4321).slice(0, 7)}`,
      role: 'supplier_operator' as const,
      active: i % 5 !== 0,
    },
  ]);

  const vehicles: Vehicle[] = suppliers.flatMap((supplier, i) =>
    Array.from({ length: 3 }, (_, j) => ({
      id: `veh-${i + 1}-${j + 1}`,
      supplierId: supplier.id,
      plate: `AA${String(1000 + i * 37 + j * 7).slice(0, 4)}${['ВА', 'КМ', 'ОР'][j % 3]}`,
      model: ['Mercedes Sprinter', 'MAN TGL', 'Renault Master', 'Volvo FL'][
        (i + j) % 4
      ],
      weightTons: clampWeight(3 + ((i + j * 3) % 30)),
    })),
  );

  const drivers: SupplierDriver[] = suppliers.flatMap((supplier, i) =>
    Array.from({ length: 2 }, (_, j) => ({
      id: `drv-${i + 1}-${j + 1}`,
      supplierId: supplier.id,
      fullName: `${['Олег', 'Тарас', 'Богдан', 'Василь'][(i + j) % 4]} ${['Іваненко', 'Петренко', 'Коваль', 'Шевченко'][(i * 2 + j) % 4]}`,
      phone: `+38063${String(4000000 + i * 9871 + j * 13).slice(0, 7)}`,
      active: true,
    })),
  );

  const staff: StaffUser[] = STAFF_SEED.map((s) => ({
    id: s.id,
    fullName: s.fullName,
    email: s.email,
    phone: s.phone,
    role: s.role,
    active: s.active,
    storeIds: s.storeIndexes.map((i) => stores[i]?.id).filter((v): v is string => !!v),
  }));

  const bookings = seedBookings(stores, suppliers, vehicles, rnd, today);
  suppliers.forEach((supplier) => {
    supplier.bookingsCount = bookings.filter(
      (b) => b.supplierId === supplier.id,
    ).length;
  });

  return {
    stores,
    suppliers,
    supplierUsers,
    vehicles,
    drivers,
    staff,
    syncRuns: seedSyncRuns(stores),
    bookings,
    audit: seedAudit(stores, suppliers, staff),
    syncRunning: false,
  };
}

function clampWeight(value: number): number {
  const stepped = Math.round(value * 2) / 2;
  return Math.min(40, Math.max(1, stepped));
}

function seedBookings(
  stores: readonly Store[],
  suppliers: readonly Supplier[],
  vehicles: readonly Vehicle[],
  rnd: () => number,
  today: string,
): Booking[] {
  const bookings: Booking[] = [];
  const statuses: BookingStatus[] = [
    'booked',
    'booked',
    'booked',
    'completed',
    'completed',
    'no_show',
    'cancelled',
  ];
  let counter = 1;
  for (const store of stores) {
    if (store.ymsStatus !== 'active' && store.ymsStatus !== 'paused') {
      continue;
    }
    if (store.ramps.length === 0 || store.slotSizeMinutes === null) {
      continue;
    }
    const count = 2 + Math.floor(rnd() * 4);
    for (let i = 0; i < count; i += 1) {
      const supplier = suppliers[Math.floor(rnd() * suppliers.length)];
      const vehicle =
        vehicles.filter((v) => v.supplierId === supplier.id)[
          Math.floor(rnd() * 3)
        ] ?? vehicles[0];
      const offset = Math.floor(rnd() * 20) - 6;
      const hour = 8 + Math.floor(rnd() * 9);
      const status = offset < 0 ? statuses[3 + (i % 4)] : statuses[i % 3];
      bookings.push({
        id: `bk-${counter++}`,
        storeId: store.id,
        supplierId: supplier.id,
        supplierName: supplier.name,
        supplierPhone: supplier.contactPhone,
        date: addDays(today, offset),
        startTime: `${String(hour).padStart(2, '0')}:00`,
        rampId: store.ramps[Math.floor(rnd() * store.ramps.length)].id,
        vehiclePlate: vehicle.plate,
        vehicleWeightTons: vehicle.weightTons,
        orderId: `ORD-${100000 + counter}`,
        status,
      });
    }
  }
  return bookings;
}

function seedSyncRuns(stores: readonly Store[]): SyncRun[] {
  const runs: SyncRun[] = [];
  const now = Date.now();
  for (let i = 0; i < 8; i += 1) {
    const startedAt = new Date(now - i * 6 * 3_600_000);
    const failed = i === 3;
    const created = failed ? 0 : i === 0 ? 2 : i % 3;
    const changed = failed ? 0 : (i * 3) % 7;
    const missing = failed ? 0 : i % 2;
    runs.push({
      id: `sync-${8 - i}`,
      startedAt: startedAt.toISOString(),
      finishedAt: new Date(startedAt.getTime() + 42_000 + i * 3_000).toISOString(),
      durationMs: 42_000 + i * 3_000,
      type: i % 4 === 0 ? 'manual' : 'auto',
      initiatedBy: i % 4 === 0 ? 'Оксана Лисенко' : null,
      status: failed ? 'error' : 'success',
      error: failed ? 'Таймаут MCP при offset=1500: обрив пагінації' : null,
      newCount: created,
      changedCount: changed,
      missingCount: missing,
      diff: {
        created: stores.slice(i, i + created).map((s) => ({
          externalId: s.externalId,
          city: s.city,
          address: s.address,
        })),
        changed: stores.slice(10 + i, 10 + i + changed).map((s) => ({
          externalId: s.externalId,
          city: s.city,
          changes: [
            {
              field: 'address',
              oldValue: s.address,
              newValue: `${s.address}, корп. 2`,
            },
            { field: 'open', oldValue: 'true', newValue: 'true' },
          ],
        })),
        missing: stores.slice(40 + i, 40 + i + missing).map((s, idx) => ({
          externalId: s.externalId,
          city: s.city,
          address: s.address,
          missingSyncCount: idx + 1,
          hasFutureBookings: idx === 0,
        })),
      },
    });
  }
  return runs;
}

function seedAudit(
  stores: readonly Store[],
  suppliers: readonly Supplier[],
  staff: readonly StaffUser[],
): AuditEntry[] {
  const entries: AuditEntry[] = [];
  const now = Date.now();
  const actors = staff.filter((s) => s.role !== 'store_operator');
  for (let i = 0; i < 45; i += 1) {
    const actor = actors[i % actors.length];
    const kind = i % 5;
    const store = stores[(i * 7) % stores.length];
    const supplier = suppliers[i % suppliers.length];
    entries.push({
      id: `aud-${45 - i}`,
      at: new Date(now - i * 3_400_000).toISOString(),
      userId: actor.id,
      userName: actor.fullName,
      role: actor.role,
      ip: `10.20.${i % 255}.${(i * 3) % 255}`,
      objectType:
        kind === 0
          ? 'store'
          : kind === 1
            ? 'supplier'
            : kind === 2
              ? 'staff_user'
              : kind === 3
                ? 'slot_block'
                : 'sync',
      objectId: kind === 1 ? supplier.id : store.id,
      objectLabel:
        kind === 1 ? supplier.name : `${store.externalId} — ${store.city}`,
      action:
        kind === 0
          ? 'update'
          : kind === 1
            ? 'status_change'
            : kind === 2
              ? 'create'
              : kind === 3
                ? 'create'
                : 'sync_run',
      changes:
        kind === 0
          ? [{ field: 'maxVehicleWeightTons', oldValue: '20', newValue: '18' }]
          : kind === 1
            ? [{ field: 'status', oldValue: 'active', newValue: 'suspended' }]
            : [],
    });
  }
  return entries;
}

export { DELAY_REASONS };
