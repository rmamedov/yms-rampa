import { Injectable } from '@angular/core';
import {
  AuthUser,
  CalendarException,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotBlock,
  SlotSizeMinutes,
  Store,
  StoreConfiguration,
  Supplier,
  SyncLogEntry,
  SyncStatus,
  SyncTrigger,
  YmsStatus,
} from '../../models';
import { MCP_BRANCHES } from '../../fixtures/mcp.fixture';
import { addDays, kyivDate } from '../../utils/time.util';
import { emptyReceivingWindows } from '../../utils/store-config.util';

type Mutable<T> = { -readonly [K in keyof T]: T[K] };

/** Картка магазину без агрегованих ресурсів — вони лежать поруч, як у бекенді. */
export type StoreCard = Omit<
  Store,
  'configuration' | 'reservedRules' | 'slotBlocks'
>;

/** Магазин + його версії конфігурації, резерви й блокування. */
export interface MockStore {
  card: StoreCard;
  configurations: StoreConfiguration[];
  reservedRules: ReservedSlotRule[];
  slotBlocks: SlotBlock[];
}

/** Мінімальний факт бронювання — джерело для KPI мок-аналітики. */
export interface MockBookingFact {
  readonly bookingId: string;
  readonly storeId: string;
  readonly city: string;
  readonly supplierId: string;
  readonly rampId: string;
  /** UTC ISO 8601 */
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly type: 'scheduled' | 'walk_in';
  readonly status:
    | 'booked'
    | 'arrived'
    | 'unloading'
    | 'completed'
    | 'cancelled'
    | 'no_show'
    | 'rejected';
  readonly waitingMinutes: number | null;
  readonly unloadingMinutes: number | null;
  readonly slotMinutes: number;
  readonly delayed: boolean;
  readonly delayReason: string | null;
  readonly rejectedReason: string | null;
  readonly palletsCount: number;
  readonly unloadedPalletsCount: number;
  readonly onTime: boolean | null;
}

export interface MockAccount extends AuthUser {
  readonly active: boolean;
}

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

const ROLE_LABELS: Readonly<Record<string, string>> = {
  super_admin: 'Суперадміністратор',
  network_manager: 'Менеджер мережі',
  store_manager: 'Менеджер магазину',
  store_operator: 'Оператор магазину',
  analyst: 'Аналітик',
};

const ACCOUNT_SEED: ReadonlyArray<
  Omit<MockAccount, 'storeIds' | 'roleLabel' | 'networkWide'> & {
    storeIndexes: number[];
  }
> = [
  {
    id: 'su-1',
    fullName: 'Руслан Мамедов',
    email: 'super.admin@silpo.ua',
    role: 'super_admin',
    active: true,
    storeIndexes: [],
  },
  {
    id: 'su-2',
    fullName: 'Оксана Лисенко',
    email: 'network.manager@silpo.ua',
    role: 'network_manager',
    active: true,
    storeIndexes: [],
  },
  {
    id: 'su-3',
    fullName: 'Павло Гончар',
    email: 'store.manager@silpo.ua',
    role: 'store_manager',
    active: true,
    storeIndexes: [0, 1, 2],
  },
  {
    id: 'su-4',
    fullName: 'Вікторія Шевчук',
    email: 'analyst@silpo.ua',
    role: 'analyst',
    active: true,
    storeIndexes: [],
  },
  {
    id: 'su-5',
    fullName: 'Микола Дяченко',
    email: 'store.operator@silpo.ua',
    role: 'store_operator',
    active: true,
    storeIndexes: [3, 4],
  },
  {
    id: 'su-6',
    fullName: 'Аліна Терещенко',
    email: 'alina.tereshchenko@silpo.ua',
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

/** Формулювання відсутніх параметрів — дослівно з ConfigurationReadiness. */
export const MISSING_WINDOWS = 'вікна прийому';
export const MISSING_RAMPS = 'активні рампи';
export const MISSING_ABSENT: readonly string[] = [
  'вікна прийому',
  'розмір слоту',
  'активні рампи',
  'максимальна маса авто',
];

export function readinessOf(config: StoreConfiguration | null): {
  configured: boolean;
  missing: readonly string[];
} {
  if (!config) {
    return { configured: false, missing: MISSING_ABSENT };
  }
  const missing: string[] = [];
  const totalMinutes = config.receivingWindows.reduce(
    (sum, w) =>
      sum +
      w.intervals.reduce((acc, i) => acc + minutesOf(i.to) - minutesOf(i.from), 0),
    0,
  );
  if (totalMinutes === 0) {
    missing.push(MISSING_WINDOWS);
  }
  if (!config.ramps.some((r) => r.enabled)) {
    missing.push(MISSING_RAMPS);
  }
  return { configured: missing.length === 0, missing };
}

function minutesOf(time: string): number {
  const [h, m] = time.split(':');
  return Number(h) * 60 + Number(m);
}

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

function makeRamps(count: number, storeId: string): Ramp[] {
  return Array.from({ length: count }, (_, i) => ({
    id: `${storeId}-ramp-${i + 1}`,
    number: i + 1,
    name: i === 0 ? 'Основна' : `Рампа ${i + 1}`,
    enabled: true,
  }));
}

export const YMS_STATUS_LABELS: Readonly<Record<YmsStatus, string>> = {
  not_configured: 'Не налаштований',
  active: 'Активний',
  paused: 'Призупинений',
  archived: 'Архівний',
};

/** STC-03: дозволені переходи статусу (Branch::allowedTransitions). */
export const ALLOWED_TRANSITIONS: Readonly<Record<YmsStatus, readonly YmsStatus[]>> = {
  not_configured: ['active', 'archived'],
  active: ['paused', 'archived'],
  paused: ['active', 'archived'],
  archived: ['not_configured'],
};

export const SYNC_STATUS_LABELS: Readonly<Record<SyncStatus, string>> = {
  running: 'Виконується',
  success: 'Успіх',
  partial: 'Успіх із конфліктами',
  failed: 'Помилка',
};

export const SYNC_TRIGGER_LABELS: Readonly<Record<SyncTrigger, string>> = {
  cron: 'Автоматичний (cron)',
  manual: 'Ручний',
  import: 'Первинний імпорт',
};

export interface MockState {
  stores: MockStore[];
  suppliers: Supplier[];
  accounts: MockAccount[];
  syncLog: SyncLogEntry[];
  bookings: MockBookingFact[];
  syncRunning: boolean;
}

/**
 * InMemory-джерело даних для роботи без бекенду (environment.useMocks).
 * Дані філій і міст походять з fixtures/silpo-branches.json.
 */
@Injectable({ providedIn: 'root' })
export class MockDb {
  readonly state: MockState = seed();

  reset(): void {
    const fresh = seed();
    this.state.stores = fresh.stores;
    this.state.suppliers = fresh.suppliers;
    this.state.accounts = fresh.accounts;
    this.state.syncLog = fresh.syncLog;
    this.state.bookings = fresh.bookings;
    this.state.syncRunning = false;
  }

  store(id: string): MockStore | undefined {
    return this.state.stores.find((s) => s.card.id === id);
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
    status: s.status,
    statusLabel: s.status === 'active' ? 'Активний' : 'Призупинений',
    storeAccess: { allStores: i % 3 !== 0, storeIds: [] },
    contacts: [
      {
        name: s.person,
        phone: `+38067${String(2000000 + i * 13457).slice(0, 7)}`,
        email: `contact${i + 1}@postachalnyk.ua`,
      },
    ],
    suspendedAt:
      s.status === 'suspended' ? new Date(Date.now() - 86_400_000).toISOString() : null,
    suspendReason: s.status === 'suspended' ? 'Прострочена заборгованість' : null,
    createdAt: new Date(Date.now() - 90 * 86_400_000).toISOString(),
    updatedAt: new Date(Date.now() - 3 * 86_400_000).toISOString(),
  }));

  const stores: MockStore[] = MCP_BRANCHES.map((branch, index) => {
    const id = `st-${branch.externalId}`;
    const bucket = index % 10;
    let status: YmsStatus;
    if (bucket <= 4) status = 'active';
    else if (bucket === 5) status = 'paused';
    else if (bucket === 9) status = 'archived';
    else status = 'not_configured';

    const configured = status === 'active' || status === 'paused';
    const ramps = makeRamps(configured ? 1 + (index % 4) : 0, id);
    const slotSize = ([30, 60, 20, 15] as SlotSizeMinutes[])[index % 4];
    const windows = configured
      ? index % 3 === 0
        ? splitWindows()
        : standardWindows()
      : emptyReceivingWindows();

    const calendarExceptions: CalendarException[] =
      configured && index % 7 === 0
        ? [
            {
              id: `exc-${addDays(today, 14)}`,
              date: addDays(today, 14),
              type: 'closed',
              intervals: [],
              reason: 'Інвентаризація',
            },
            {
              id: `exc-${addDays(today, 21)}`,
              date: addDays(today, 21),
              type: 'custom',
              intervals: [{ from: '09:00', to: '13:00' }],
              reason: 'Скорочений день перед святом',
            },
          ]
        : [];

    const configuration: StoreConfiguration | null = configured
      ? {
          id: `${id}-cfg-1`,
          storeId: id,
          version: 1,
          effectiveFrom: `${addDays(today, -30)}T00:00:00+00:00`,
          receivingWindows: windows,
          slotSizeMinutes: slotSize,
          ramps,
          maxVehicleWeightTons: clampWeight(5 + Math.round(rnd() * 60) / 2),
          leadTimeMinutes: [120, 240, 720, 1440][index % 4],
          bookingHorizonDays: [14, 21, 30][index % 3],
          noShowGraceMinutes: 30,
          holdMaxMinutes: 15,
          calendarExceptions,
          configured: true,
          missingSettings: [],
          createdBy: 'su-2',
          createdAt: new Date(Date.now() - 30 * 86_400_000).toISOString(),
          schemaVersion: 1,
        }
      : null;

    const reservedRules: ReservedSlotRule[] =
      configuration && index % 5 === 0 && ramps.length > 0
        ? [
            {
              id: `${id}-res-1`,
              storeId: id,
              supplierId: suppliers[index % suppliers.length].id,
              rampId: ramps[0].id,
              slotStartTime: '08:00',
              dayOfWeek: 2,
              date: null,
              validFrom: `${addDays(today, -30)}T00:00:00+00:00`,
              validTo: null,
              active: true,
            },
          ]
        : [];

    const slotBlocks: SlotBlock[] =
      configuration && index % 11 === 0 && ramps.length > 0
        ? [
            {
              id: `${id}-blk-1`,
              storeId: id,
              rampIds: [ramps[0].id],
              coversAllRamps: false,
              blockFrom: `${addDays(today, 3)}T09:00:00+00:00`,
              blockTo: `${addDays(today, 3)}T12:00:00+00:00`,
              reason: BLOCK_REASONS[index % BLOCK_REASONS.length],
              releasedAt: null,
              createdAt: new Date().toISOString(),
            },
          ]
        : [];

    const readiness = readinessOf(configuration);
    const displayName = `Сільпо ${branch.externalId}`;
    const addressOverride =
      index % 13 === 0 ? `${branch.address} (вʼїзд з двору)` : null;

    const card: StoreCard = {
      ...branch,
      id,
      displayName,
      effectiveDisplayName: displayName,
      phone:
        index % 4 === 0 ? null : `+38044${String(3000000 + index * 719).slice(0, 7)}`,
      addressOverride,
      effectiveAddress: addressOverride ?? branch.address,
      ymsStatus: status,
      ymsStatusLabel: YMS_STATUS_LABELS[status],
      allowedTransitions: ALLOWED_TRANSITIONS[status],
      visibleToSuppliers: status === 'active',
      isConfigured: readiness.configured,
      missingSettings: readiness.missing,
      eligible: status === 'active' && readiness.configured && branch.open,
      ineligibilityReasons: branch.open
        ? []
        : [{ code: 'branch_closed', message: 'Філія закрита в MCP' }],
      missingSyncCount: 0,
      lastSyncedAt: new Date(Date.now() - (index % 12) * 3_600_000).toISOString(),
      createdAt: new Date(Date.now() - 120 * 86_400_000).toISOString(),
      updatedAt: new Date(Date.now() - 2 * 86_400_000).toISOString(),
      archivedAt: status === 'archived' ? new Date().toISOString() : null,
      activeConfigurationVersion: configuration?.version ?? null,
    };

    return {
      card,
      configurations: configuration ? [configuration] : [],
      reservedRules,
      slotBlocks,
    };
  });

  // whitelist-постачальникам призначаємо кілька активних магазинів
  const activeStoreIds = stores
    .filter((s) => s.card.ymsStatus === 'active')
    .map((s) => s.card.id);
  suppliers.forEach((supplier, i) => {
    if (!supplier.storeAccess.allStores) {
      supplier.storeAccess = {
        allStores: false,
        storeIds: activeStoreIds.slice(i * 3, i * 3 + 6),
      };
    }
  });

  const accounts: MockAccount[] = ACCOUNT_SEED.map((a) => ({
    id: a.id,
    fullName: a.fullName,
    email: a.email,
    role: a.role,
    roleLabel: ROLE_LABELS[a.role] ?? a.role,
    active: a.active,
    networkWide: a.storeIndexes.length === 0,
    storeIds: a.storeIndexes
      .map((i) => stores[i]?.card.id)
      .filter((v): v is string => !!v),
  }));

  return {
    stores,
    suppliers,
    accounts,
    syncLog: seedSyncLog(),
    bookings: seedBookings(stores, suppliers, rnd, today),
    syncRunning: false,
  };
}

function clampWeight(value: number): number {
  const stepped = Math.round(value * 2) / 2;
  return Math.min(40, Math.max(1, stepped));
}

function seedBookings(
  stores: readonly MockStore[],
  suppliers: readonly Supplier[],
  rnd: () => number,
  today: string,
): MockBookingFact[] {
  const facts: MockBookingFact[] = [];
  let counter = 1;
  for (const store of stores) {
    const config = store.configurations[0];
    if (!config || store.card.ymsStatus === 'archived') {
      continue;
    }
    const count = 2 + Math.floor(rnd() * 4);
    for (let i = 0; i < count; i += 1) {
      const supplier = suppliers[Math.floor(rnd() * suppliers.length)];
      const offset = Math.floor(rnd() * 20) - 12;
      const hour = 8 + Math.floor(rnd() * 9);
      const date = addDays(today, offset);
      const start = `${date}T${String(hour).padStart(2, '0')}:00:00+00:00`;
      const end = `${date}T${String(hour).padStart(2, '0')}:${String(
        config.slotSizeMinutes % 60,
      ).padStart(2, '0')}:00+00:00`;
      const past = offset < 0;
      const roll = rnd();
      const status = !past
        ? 'booked'
        : roll < 0.7
          ? 'completed'
          : roll < 0.82
            ? 'no_show'
            : roll < 0.92
              ? 'cancelled'
              : 'rejected';
      const delayed = past && rnd() < 0.25;
      facts.push({
        bookingId: `bk-${counter++}`,
        storeId: store.card.id,
        city: store.card.city,
        supplierId: supplier.id,
        rampId: config.ramps[Math.floor(rnd() * config.ramps.length)]?.id ?? 'r1',
        slotStart: start,
        slotEnd: end,
        type: rnd() < 0.15 ? 'walk_in' : 'scheduled',
        status,
        waitingMinutes: status === 'completed' ? Math.round(rnd() * 40) : null,
        unloadingMinutes:
          status === 'completed'
            ? Math.round(config.slotSizeMinutes * (0.5 + rnd() * 0.8))
            : null,
        slotMinutes: config.slotSizeMinutes,
        delayed,
        delayReason: delayed
          ? DELAY_REASONS[Math.floor(rnd() * DELAY_REASONS.length)]
          : null,
        rejectedReason: status === 'rejected' ? 'no_documents' : null,
        palletsCount: 4 + Math.floor(rnd() * 20),
        unloadedPalletsCount: status === 'completed' ? 4 + Math.floor(rnd() * 20) : 0,
        onTime: status === 'completed' ? !delayed : null,
      });
    }
  }
  return facts;
}

function seedSyncLog(): SyncLogEntry[] {
  const entries: SyncLogEntry[] = [];
  const now = Date.now();
  for (let i = 0; i < 8; i += 1) {
    const startedAt = new Date(now - i * 6 * 3_600_000);
    const failed = i === 3;
    const status: SyncStatus = failed ? 'failed' : i === 5 ? 'partial' : 'success';
    const trigger: SyncTrigger = i % 4 === 0 ? 'manual' : 'cron';
    entries.push({
      id: `sync-${8 - i}`,
      status,
      statusLabel: SYNC_STATUS_LABELS[status],
      trigger,
      triggerLabel: SYNC_TRIGGER_LABELS[trigger],
      initiator: trigger === 'manual' ? 'su-2' : null,
      source: 'mcp',
      startedAt: startedAt.toISOString(),
      finishedAt: new Date(startedAt.getTime() + 42_000 + i * 3_000).toISOString(),
      durationSeconds: 42 + i * 3,
      fetched: failed ? 0 : 1200 + i,
      created: failed ? 0 : i === 0 ? 2 : i % 3,
      updated: failed ? 0 : (i * 3) % 7,
      missing: failed ? 0 : i % 2,
      archived: 0,
      conflicts: status === 'partial' ? 2 : 0,
      skipped: failed ? 0 : i % 4,
      errors: failed ? ['Таймаут MCP при offset=1500: обрив пагінації'] : [],
    });
  }
  return entries;
}

export { DELAY_REASONS };
