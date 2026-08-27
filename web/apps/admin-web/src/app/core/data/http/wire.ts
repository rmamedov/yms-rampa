/**
 * Формат «дроту» контуру /api/admin/v1 — рівно те, що віддають контролери
 * бекенду, і перетворення в моделі admin-web.
 *
 * Джерела:
 *   store-service     BranchPresenter, ConfigurationPresenter, BranchSyncService;
 *   partner-service   Infrastructure\Http\View::supplier;
 *   identity-staff    AuthController::tokenResponse + LoginResult::profile.
 */
import {
  AuthSession,
  AuthTokens,
  CalendarException,
  CityOption,
  DayOfWeek,
  ExceptionType,
  McpBranch,
  Page,
  PageQuery,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotBlock,
  SlotSizeMinutes,
  StaffRole,
  Store,
  StoreConfiguration,
  StoreListRow,
  Supplier,
  SupplierContact,
  SyncLog,
  SyncLogEntry,
  SyncReport,
  SyncStatus,
  SyncTrigger,
  TimeInterval,
  YmsStatus,
} from '../../models';

// ---------------------------------------------------------------------------
// identity-staff-service
// ---------------------------------------------------------------------------

export interface WireAuthUser {
  readonly id: string;
  readonly email: string;
  readonly fullName: string;
  readonly role: string;
  readonly roleLabel: string;
  readonly scope: { readonly storeIds: readonly string[]; readonly networkWide: boolean };
  readonly twoFactorEnabled: boolean;
  readonly permissions: readonly string[];
}

export interface WireTokenResponse {
  readonly tokenType: string;
  readonly accessToken: string;
  readonly expiresIn: number;
  readonly accessExpiresAt: string;
  readonly refreshToken: string;
  readonly refreshExpiresAt: string;
  readonly sessionId: string;
  readonly user: WireAuthUser;
}

export function toTokens(wire: WireTokenResponse): AuthTokens {
  return {
    accessToken: wire.accessToken,
    refreshToken: wire.refreshToken,
    expiresAt: wire.accessExpiresAt,
    sessionId: wire.sessionId,
  };
}

export function toSession(wire: WireTokenResponse): AuthSession {
  return {
    user: {
      id: wire.user.id,
      fullName: wire.user.fullName,
      email: wire.user.email,
      role: wire.user.role as StaffRole,
      roleLabel: wire.user.roleLabel,
      storeIds: [...(wire.user.scope?.storeIds ?? [])],
      networkWide: wire.user.scope?.networkWide ?? false,
    },
    tokens: toTokens(wire),
  };
}

// ---------------------------------------------------------------------------
// store-service: список і картка магазину
// ---------------------------------------------------------------------------

export interface WirePage<T> {
  readonly items: readonly T[];
  readonly total: number;
  readonly page: number;
  readonly perPage: number;
  readonly pages?: number;
  readonly emptyMessage?: string | null;
}

export function toPage<W, T>(wire: WirePage<W>, map: (item: W) => T): Page<T> {
  return {
    items: (wire.items ?? []).map(map),
    total: wire.total ?? 0,
    page: wire.page ?? 1,
    pageSize: wire.perPage ?? 20,
    emptyMessage: wire.emptyMessage ?? null,
  };
}

export interface WireStoreRow {
  readonly branchId: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
  readonly ymsStatus: string;
  readonly ymsStatusLabel: string;
  readonly configured: boolean;
  readonly missingSettings: readonly string[];
  readonly rampCount: number;
  readonly maxVehicleWeightTons: number | null;
  readonly visibleToSuppliers: boolean;
  readonly eligible: boolean;
  readonly syncedAt: string;
}

export function toStoreListRow(wire: WireStoreRow): StoreListRow {
  return {
    id: wire.branchId,
    externalId: wire.externalId,
    displayName: wire.displayName,
    city: wire.city,
    address: wire.address,
    ymsStatus: wire.ymsStatus as YmsStatus,
    ymsStatusLabel: wire.ymsStatusLabel,
    isConfigured: wire.configured,
    missingSettings: [...(wire.missingSettings ?? [])],
    rampCount: wire.rampCount,
    maxVehicleWeightTons: wire.maxVehicleWeightTons,
    visibleToSuppliers: wire.visibleToSuppliers,
    eligible: wire.eligible,
    lastSyncedAt: wire.syncedAt,
  };
}

export interface WireCity {
  readonly city: string;
  readonly storeCount: number;
}

export function toCityOption(wire: WireCity): CityOption {
  return { city: wire.city, storeCount: wire.storeCount ?? 0 };
}

export interface WireMcpData {
  readonly branchId: string;
  readonly companyId: string;
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: number | string | null;
  readonly longitude: number | string | null;
  readonly hasPickup: boolean | null;
  readonly open: boolean;
}

export interface WireStoreCard {
  readonly branchId: string;
  readonly mcpData: WireMcpData;
  readonly displayName: string | null;
  readonly effectiveDisplayName: string;
  readonly phone: string | null;
  readonly addressOverride: string | null;
  readonly effectiveAddress: string;
  readonly ymsStatus: string;
  readonly ymsStatusLabel: string;
  readonly allowedTransitions: readonly string[];
  readonly visibleToSuppliers: boolean;
  readonly configured: boolean;
  readonly missingSettings: readonly string[];
  readonly eligible: boolean;
  readonly ineligibilityReasons: ReadonlyArray<{ code: string; message: string }>;
  readonly missingSyncCount: number;
  readonly syncedAt: string;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly archivedAt: string | null;
  readonly activeConfigurationVersion: number | null;
}

function coord(value: number | string | null): string | null {
  return value === null || value === undefined ? null : String(value);
}

export function toMcpBranch(wire: WireMcpData): McpBranch {
  return {
    branchId: wire.branchId,
    companyId: wire.companyId,
    externalId: wire.externalId,
    city: wire.city,
    address: wire.address,
    latitude: coord(wire.latitude),
    longitude: coord(wire.longitude),
    hasPickup: wire.hasPickup,
    open: wire.open,
  };
}

export function toStore(
  wire: WireStoreCard,
  configuration: StoreConfiguration | null,
  reservedRules: readonly ReservedSlotRule[],
  slotBlocks: readonly SlotBlock[],
): Store {
  return {
    ...toMcpBranch(wire.mcpData),
    id: wire.branchId,
    displayName: wire.displayName,
    effectiveDisplayName: wire.effectiveDisplayName,
    phone: wire.phone,
    addressOverride: wire.addressOverride,
    effectiveAddress: wire.effectiveAddress,
    ymsStatus: wire.ymsStatus as YmsStatus,
    ymsStatusLabel: wire.ymsStatusLabel,
    allowedTransitions: (wire.allowedTransitions ?? []) as readonly YmsStatus[],
    visibleToSuppliers: wire.visibleToSuppliers,
    isConfigured: wire.configured,
    missingSettings: [...(wire.missingSettings ?? [])],
    eligible: wire.eligible,
    ineligibilityReasons: [...(wire.ineligibilityReasons ?? [])],
    missingSyncCount: wire.missingSyncCount ?? 0,
    lastSyncedAt: wire.syncedAt,
    createdAt: wire.createdAt,
    updatedAt: wire.updatedAt,
    archivedAt: wire.archivedAt,
    activeConfigurationVersion: wire.activeConfigurationVersion,
    configuration,
    reservedRules,
    slotBlocks,
  };
}

// ---------------------------------------------------------------------------
// store-service: конфігурація, резерви, блокування
// ---------------------------------------------------------------------------

export interface WireRamp {
  readonly rampId: string;
  readonly number: number;
  readonly name: string | null;
  readonly active: boolean;
}

export function toRamp(wire: WireRamp): Ramp {
  return {
    id: wire.rampId,
    number: wire.number,
    name: wire.name,
    enabled: wire.active,
  };
}

export function fromRamp(ramp: Ramp): WireRamp {
  return {
    rampId: ramp.id,
    number: ramp.number,
    name: ramp.name,
    active: ramp.enabled,
  };
}

export interface WireReceivingWindow {
  readonly dayOfWeek: number;
  readonly intervals: readonly TimeInterval[];
}

export interface WireCalendarException {
  readonly date: string;
  readonly type: string;
  readonly reason: string;
  readonly intervals: readonly TimeInterval[];
}

/** Бекенд ідентифікатора винятку не зберігає — ключ у нього дата. */
export function toCalendarException(wire: WireCalendarException): CalendarException {
  return {
    id: `exc-${wire.date}`,
    date: wire.date,
    type: wire.type as ExceptionType,
    reason: wire.reason ?? '',
    intervals: [...(wire.intervals ?? [])],
  };
}

export function fromCalendarException(
  exception: CalendarException,
): WireCalendarException {
  return {
    date: exception.date,
    type: exception.type,
    reason: exception.reason,
    intervals: exception.intervals.map((i) => ({ from: i.from, to: i.to })),
  };
}

export interface WireConfiguration {
  readonly id: string;
  readonly storeId: string;
  readonly version: number;
  readonly effectiveFrom: string;
  readonly receivingWindows: readonly WireReceivingWindow[];
  readonly slotSizeMinutes: number;
  readonly ramps: readonly WireRamp[];
  readonly maxVehicleWeightTons: number;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly noShowGraceMinutes: number;
  readonly holdMaxMinutes: number;
  readonly calendarExceptions: readonly WireCalendarException[];
  readonly configured: boolean;
  readonly missingSettings: readonly string[];
  readonly createdBy: string | null;
  readonly createdAt: string | null;
  readonly schemaVersion: number;
}

export function toConfiguration(wire: WireConfiguration): StoreConfiguration {
  const windows: ReceivingWindow[] = (wire.receivingWindows ?? []).map((w) => ({
    dayOfWeek: w.dayOfWeek as DayOfWeek,
    intervals: [...(w.intervals ?? [])],
  }));
  return {
    id: wire.id,
    storeId: wire.storeId,
    version: wire.version,
    effectiveFrom: wire.effectiveFrom,
    receivingWindows: windows,
    slotSizeMinutes: wire.slotSizeMinutes as SlotSizeMinutes,
    ramps: (wire.ramps ?? []).map(toRamp),
    maxVehicleWeightTons: wire.maxVehicleWeightTons,
    leadTimeMinutes: wire.leadTimeMinutes,
    bookingHorizonDays: wire.bookingHorizonDays,
    noShowGraceMinutes: wire.noShowGraceMinutes,
    holdMaxMinutes: wire.holdMaxMinutes,
    calendarExceptions: (wire.calendarExceptions ?? []).map(toCalendarException),
    configured: wire.configured,
    missingSettings: [...(wire.missingSettings ?? [])],
    createdBy: wire.createdBy,
    createdAt: wire.createdAt,
    schemaVersion: wire.schemaVersion,
  };
}

export interface WireReservedRule {
  readonly id: string;
  readonly storeId: string;
  readonly supplierId: string;
  readonly rampId: string;
  readonly slotStartTime: string;
  readonly dayOfWeek: number | null;
  readonly date: string | null;
  readonly validFrom: string;
  readonly validTo: string | null;
  readonly active: boolean;
}

export function toReservedRule(wire: WireReservedRule): ReservedSlotRule {
  return {
    id: wire.id,
    storeId: wire.storeId,
    supplierId: wire.supplierId,
    rampId: wire.rampId,
    slotStartTime: wire.slotStartTime,
    dayOfWeek: wire.dayOfWeek === null ? null : (wire.dayOfWeek as DayOfWeek),
    date: wire.date,
    validFrom: wire.validFrom,
    validTo: wire.validTo,
    active: wire.active,
  };
}

export interface WireSlotBlock {
  readonly id: string;
  readonly storeId: string;
  readonly rampIds: readonly string[];
  readonly coversAllRamps: boolean;
  readonly blockFrom: string;
  readonly blockTo: string;
  readonly reason: string;
  readonly releasedAt: string | null;
  readonly createdBy: string | null;
  readonly createdAt: string | null;
}

export function toSlotBlock(wire: WireSlotBlock): SlotBlock {
  return {
    id: wire.id,
    storeId: wire.storeId,
    rampIds: [...(wire.rampIds ?? [])],
    coversAllRamps: wire.coversAllRamps,
    blockFrom: wire.blockFrom,
    blockTo: wire.blockTo,
    reason: wire.reason ?? '',
    releasedAt: wire.releasedAt,
    createdAt: wire.createdAt,
  };
}

/** Зведення POST /stores/bulk/status. */
export interface WireBulkStatus {
  readonly requested: number;
  readonly succeeded: readonly string[];
  readonly failed: ReadonlyArray<{
    branchId: string;
    message: string;
    code: string;
  }>;
}

// ---------------------------------------------------------------------------
// store-service: синхронізація
// ---------------------------------------------------------------------------

export interface WireSyncEntry {
  readonly id: string;
  readonly status: string;
  readonly statusLabel: string;
  readonly trigger: string;
  readonly triggerLabel: string;
  readonly initiator: string | null;
  readonly source: string | null;
  readonly startedAt: string;
  readonly finishedAt: string | null;
  readonly durationSeconds: number | null;
  readonly fetched: number;
  readonly created: number;
  readonly updated: number;
  readonly missing: number;
  readonly archived: number;
  readonly conflicts: number;
  readonly skipped: number;
  readonly errors: readonly string[];
}

export function toSyncEntry(wire: WireSyncEntry): SyncLogEntry {
  return {
    id: wire.id,
    status: wire.status as SyncStatus,
    statusLabel: wire.statusLabel,
    trigger: wire.trigger as SyncTrigger,
    triggerLabel: wire.triggerLabel,
    initiator: wire.initiator,
    source: wire.source,
    startedAt: wire.startedAt,
    finishedAt: wire.finishedAt,
    durationSeconds: wire.durationSeconds,
    fetched: wire.fetched ?? 0,
    created: wire.created ?? 0,
    updated: wire.updated ?? 0,
    missing: wire.missing ?? 0,
    archived: wire.archived ?? 0,
    conflicts: wire.conflicts ?? 0,
    skipped: wire.skipped ?? 0,
    errors: [...(wire.errors ?? [])],
  };
}

export interface WireSyncLog {
  readonly items: readonly WireSyncEntry[];
  readonly total: number;
  readonly page: number;
  readonly perPage: number;
  readonly lastSuccessfulAt: string | null;
  readonly running: boolean;
}

export function toSyncLog(wire: WireSyncLog): SyncLog {
  return {
    items: (wire.items ?? []).map(toSyncEntry),
    total: wire.total ?? 0,
    page: wire.page ?? 1,
    perPage: wire.perPage ?? 20,
    lastSuccessfulAt: wire.lastSuccessfulAt ?? null,
    running: wire.running ?? false,
  };
}

export interface WireSyncReport {
  readonly status: string;
  readonly trigger: string;
  readonly initiator: string | null;
  readonly startedAt: string;
  readonly finishedAt: string;
  readonly durationSeconds: number;
  readonly fetched: number;
  readonly skipped: number;
  readonly created: number;
  readonly updated: number;
  readonly missing: number;
  readonly archived: number;
  readonly conflicts: number;
  readonly ineligible: number;
  readonly eligible: number;
  readonly ineligibleByReason: Readonly<Record<string, number>>;
  readonly errors: readonly string[];
}

export function toSyncReport(wire: WireSyncReport): SyncReport {
  return {
    status: wire.status as SyncStatus,
    trigger: wire.trigger as SyncTrigger,
    initiator: wire.initiator,
    startedAt: wire.startedAt,
    finishedAt: wire.finishedAt,
    durationSeconds: wire.durationSeconds,
    fetched: wire.fetched ?? 0,
    skipped: wire.skipped ?? 0,
    created: wire.created ?? 0,
    updated: wire.updated ?? 0,
    missing: wire.missing ?? 0,
    archived: wire.archived ?? 0,
    conflicts: wire.conflicts ?? 0,
    ineligible: wire.ineligible ?? 0,
    eligible: wire.eligible ?? 0,
    ineligibleByReason: { ...(wire.ineligibleByReason ?? {}) },
    errors: [...(wire.errors ?? [])],
  };
}

// ---------------------------------------------------------------------------
// partner-service
// ---------------------------------------------------------------------------

export interface WireSupplier {
  readonly id: string;
  readonly name: string;
  readonly edrpou: string | null;
  readonly status: string;
  readonly statusLabel: string;
  readonly storeAccess: { allStores: boolean; storeIds: readonly string[] };
  readonly contacts: readonly SupplierContact[];
  readonly suspendedAt: string | null;
  readonly suspendReason: string | null;
  readonly createdAt: string | null;
  readonly updatedAt: string | null;
}

export function toSupplier(wire: WireSupplier): Supplier {
  return {
    id: wire.id,
    name: wire.name,
    edrpou: wire.edrpou,
    status: wire.status === 'suspended' ? 'suspended' : 'active',
    statusLabel: wire.statusLabel,
    storeAccess: {
      allStores: wire.storeAccess?.allStores ?? true,
      storeIds: [...(wire.storeAccess?.storeIds ?? [])],
    },
    contacts: (wire.contacts ?? []).map((c) => ({
      name: c.name,
      phone: c.phone ?? null,
      email: c.email ?? null,
    })),
    suspendedAt: wire.suspendedAt,
    suspendReason: wire.suspendReason,
    createdAt: wire.createdAt,
    updatedAt: wire.updatedAt,
  };
}

/** AdminSupplierController віддає limit/offset, а не page/perPage. */
export interface WireSupplierList {
  readonly items: readonly WireSupplier[];
  readonly total: number;
  readonly limit: number;
  readonly offset: number;
}

export function toSupplierPage(
  wire: WireSupplierList,
  query: PageQuery,
): Page<Supplier> {
  return {
    items: (wire.items ?? []).map(toSupplier),
    total: wire.total ?? 0,
    page: query.page,
    pageSize: wire.limit ?? query.pageSize,
  };
}
