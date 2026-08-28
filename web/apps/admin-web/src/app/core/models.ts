/**
 * Моделі даних admin-web.
 *
 * Джерело істини — реальний контракт бекенду контуру /api/admin/v1
 * (store-service, partner-service, identity-staff-service, analytics-service).
 * Дати/часи — UTC ISO 8601 (ADM-03), локальні часи прийому — HH:MM Europe/Kyiv.
 */

// ---------------------------------------------------------------------------
// Загальне
// ---------------------------------------------------------------------------

export type SortDirection = 'asc' | 'desc';

/**
 * Пагінація UI. У запит іде як page/perPage (store-service, sync)
 * або limit/offset (partner-service) — перетворення робить HTTP-клієнт.
 */
export interface PageQuery {
  readonly page: number;
  readonly pageSize: PageSize;
  readonly sort?: string;
  readonly direction?: SortDirection;
}

/** BranchCriteria::ALLOWED_PER_PAGE — інші значення бекенд відхиляє 422. */
export type PageSize = 20 | 50 | 100;
export const PAGE_SIZES: readonly PageSize[] = [20, 50, 100];

export interface Page<T> {
  readonly items: readonly T[];
  readonly total: number;
  readonly page: number;
  readonly pageSize: number;
  /** STL-06: бекенд сам формує текст порожньої вибірки. */
  readonly emptyMessage?: string | null;
}

/** Колонки, за якими store-service вміє сортувати список магазинів. */
export const STORE_SORT_COLUMNS: readonly string[] = [
  'city',
  'externalId',
  'ymsStatus',
  'address',
  'syncedAt',
];

export interface FieldChange {
  readonly field: string;
  readonly oldValue: string | null;
  readonly newValue: string | null;
}

/** Результат масової дії (UI-02). */
export interface BulkResultRow {
  readonly id: string;
  readonly label: string;
  readonly ok: boolean;
  readonly message?: string;
}

// ---------------------------------------------------------------------------
// RBAC
// ---------------------------------------------------------------------------

export type StaffRole =
  | 'super_admin'
  | 'network_manager'
  | 'store_manager'
  | 'store_operator'
  | 'analyst';

export type PartnerRole = 'supplier_admin' | 'supplier_operator' | 'driver';
export type AnyRole = StaffRole | PartnerRole;

export type Permission =
  | 'store.read'
  | 'store.configure'
  | 'store.sync.manage'
  | 'slot.read'
  | 'slot.block'
  | 'slot.reserve'
  | 'booking.read.all'
  | 'booking.cancel.any'
  | 'supplier.read'
  | 'supplier.manage'
  | 'analytics.view'
  | 'users.manage.staff'
  | 'users.manage.supplier'
  | 'roles.assign'
  | 'audit.read';

/** ✓ — повне право, S — лише в межах скоупа, — заборонено (RBAC-10). */
export type PermissionGrant = 'full' | 'scoped' | 'denied';

export interface AuthUser {
  readonly id: string;
  readonly fullName: string;
  readonly email: string;
  readonly role: StaffRole;
  readonly roleLabel: string;
  /** Скоуп магазинів; порожній масив = нуль доступу (RBAC-13). */
  readonly storeIds: readonly string[];
  /** RBAC-16: доступ до всієї мережі. */
  readonly networkWide: boolean;
}

export interface AuthTokens {
  readonly accessToken: string;
  readonly refreshToken: string;
  /** UTC ISO 8601, поле accessExpiresAt відповіді бекенду. */
  readonly expiresAt: string;
  readonly sessionId: string;
}

export interface AuthSession {
  readonly user: AuthUser;
  readonly tokens: AuthTokens;
}

// ---------------------------------------------------------------------------
// Користувачі мережі (identity-staff-service, розділ 4.7)
// ---------------------------------------------------------------------------

/**
 * Скоуп облікового запису у відповіді `/api/admin/v1/users`.
 *
 * `zeroAccess` — ОКРЕМА ознака від бекенду, а не здогадка інтерфейсу:
 * для store_manager і store_operator порожній `storeIds` означає НУЛЬ
 * доступу, а не доступ до всієї мережі (RBAC-13).
 */
export interface StaffUserScope {
  readonly storeIds: readonly string[];
  /** RBAC-16: роль зі скоупом «вся мережа». */
  readonly networkWide: boolean;
  /** RBAC-13: роль обмежена переліком магазинів. */
  readonly storeScoped: boolean;
  readonly zeroAccess: boolean;
  /** Готовий текст попередження від бекенду; null — попереджати нема про що. */
  readonly warning: string | null;
}

export interface StaffUser {
  readonly id: string;
  readonly email: string;
  readonly fullName: string;
  /** RBAC-04: рівно одна роль. */
  readonly role: StaffRole;
  readonly roleLabel: string;
  readonly scope: StaffUserScope;
  readonly active: boolean;
  readonly twoFactorEnabled: boolean;
  readonly lastLoginAt: string | null;
  readonly createdAt: string | null;
  readonly updatedAt: string | null;
}

/**
 * Відповідь на створення акаунта і на скидання пароля: пароль приходить
 * РІВНО ОДИН РАЗ і ніде не зберігається (AUTH-61).
 */
export interface StaffUserCredentials {
  readonly user: StaffUser;
  readonly login: string;
  readonly password: string;
  readonly passwordGenerated: boolean;
  readonly passwordNotice: string;
}

export type StaffUserStatusFilter = '' | 'active' | 'inactive';

// ---------------------------------------------------------------------------
// Магазини (store-service)
// ---------------------------------------------------------------------------

export type YmsStatus = 'not_configured' | 'active' | 'paused' | 'archived';
export const YMS_STATUSES: readonly YmsStatus[] = [
  'not_configured',
  'active',
  'paused',
  'archived',
];

export type SlotSizeMinutes = 15 | 20 | 30 | 60;
export const SLOT_SIZES: readonly SlotSizeMinutes[] = [15, 20, 30, 60];

/** Блок mcpData картки магазину — read-only (STC-01, INT-03). */
export interface McpBranch {
  readonly branchId: string;
  readonly companyId: string;
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: string | null;
  readonly longitude: string | null;
  readonly hasPickup: boolean | null;
  readonly open: boolean;
}

/** 1 = понеділок … 7 = неділя (ISO-8601). */
export type DayOfWeek = 1 | 2 | 3 | 4 | 5 | 6 | 7;
export const DAYS_OF_WEEK: readonly DayOfWeek[] = [1, 2, 3, 4, 5, 6, 7];

export interface TimeInterval {
  /** HH:MM, локальний час магазину */
  readonly from: string;
  readonly to: string;
}

export interface ReceivingWindow {
  readonly dayOfWeek: DayOfWeek;
  readonly intervals: readonly TimeInterval[];
}

export type ExceptionType = 'closed' | 'custom';

/**
 * Виняток календаря. Бекенд ідентифікатора не зберігає — ключем є дата,
 * тож `id` формується детерміновано з дати (див. mapCalendarException).
 */
export interface CalendarException {
  readonly id: string;
  /** YYYY-MM-DD, локальна дата магазину */
  readonly date: string;
  readonly type: ExceptionType;
  readonly intervals: readonly TimeInterval[];
  readonly reason: string;
}

/** Рампа конфігурації: rampId/number/name/active у контракті бекенду. */
export interface Ramp {
  readonly id: string;
  readonly number: number;
  readonly name: string | null;
  readonly enabled: boolean;
}

/** Правило резерву — окремий ресурс /stores/{id}/reserved-slot-rules. */
export interface ReservedSlotRule {
  readonly id: string;
  readonly storeId: string;
  readonly supplierId: string;
  readonly rampId: string;
  /** HH:MM */
  readonly slotStartTime: string;
  /** рівно одне з dayOfWeek / date (STC-40) */
  readonly dayOfWeek: DayOfWeek | null;
  readonly date: string | null;
  /** UTC ISO 8601 */
  readonly validFrom: string;
  readonly validTo: string | null;
  readonly active: boolean;
}

/** Блокування слотів — окремий ресурс /stores/{id}/slot-blocks. */
export interface SlotBlock {
  readonly id: string;
  readonly storeId: string;
  /** порожній масив = усі рампи */
  readonly rampIds: readonly string[];
  readonly coversAllRamps: boolean;
  /** UTC ISO 8601 */
  readonly blockFrom: string;
  readonly blockTo: string;
  readonly reason: string;
  /** STC-52: заповнено після дострокового зняття. */
  readonly releasedAt: string | null;
  readonly createdAt: string | null;
}

/** Версія конфігурації прийому (DATA-09): редагування створює НОВУ версію. */
export interface StoreConfiguration {
  readonly id: string;
  readonly storeId: string;
  readonly version: number;
  /** UTC ISO 8601 */
  readonly effectiveFrom: string;
  readonly receivingWindows: readonly ReceivingWindow[];
  readonly slotSizeMinutes: SlotSizeMinutes;
  readonly ramps: readonly Ramp[];
  readonly maxVehicleWeightTons: number;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly noShowGraceMinutes: number;
  readonly holdMaxMinutes: number;
  readonly calendarExceptions: readonly CalendarException[];
  readonly configured: boolean;
  readonly missingSettings: readonly string[];
  readonly createdBy: string | null;
  readonly createdAt: string | null;
  readonly schemaVersion: number;
}

export interface IneligibilityReason {
  readonly code: string;
  readonly message: string;
}

/** Картка магазину: GET /api/admin/v1/stores/{storeId} + чинна конфігурація. */
export interface Store extends McpBranch {
  readonly id: string;
  readonly displayName: string | null;
  readonly effectiveDisplayName: string;
  readonly phone: string | null;
  readonly addressOverride: string | null;
  readonly effectiveAddress: string;
  readonly ymsStatus: YmsStatus;
  readonly ymsStatusLabel: string;
  /** STC-03: перелік дозволених переходів рахує бекенд. */
  readonly allowedTransitions: readonly YmsStatus[];
  readonly visibleToSuppliers: boolean;
  /** STL-04: ознаку «Налаштовано» обчислює store-service. */
  readonly isConfigured: boolean;
  readonly missingSettings: readonly string[];
  readonly eligible: boolean;
  readonly ineligibilityReasons: readonly IneligibilityReason[];
  readonly missingSyncCount: number;
  readonly lastSyncedAt: string;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly archivedAt: string | null;
  readonly activeConfigurationVersion: number | null;
  /** null — конфігурації ще немає (GET .../configurations/current → 404). */
  readonly configuration: StoreConfiguration | null;
  readonly reservedRules: readonly ReservedSlotRule[];
  readonly slotBlocks: readonly SlotBlock[];
}

/** Рядок таблиці «Список магазинів» (STL-01). */
export interface StoreListRow {
  readonly id: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
  readonly ymsStatus: YmsStatus;
  readonly ymsStatusLabel: string;
  readonly isConfigured: boolean;
  readonly missingSettings: readonly string[];
  readonly rampCount: number;
  readonly maxVehicleWeightTons: number | null;
  readonly visibleToSuppliers: boolean;
  readonly eligible: boolean;
  readonly lastSyncedAt: string;
}

export interface StoreListFilter {
  readonly search: string;
  readonly cities: readonly string[];
  readonly statuses: readonly YmsStatus[];
  /** null = будь-який */
  readonly configured: boolean | null;
}

/** Довідник міст: GET /stores/cities → { items: [{ city, storeCount }] }. */
export interface CityOption {
  readonly city: string;
  readonly storeCount: number;
}

/**
 * Значення фільтра «Місто» для філій, у яких місто в довіднику MCP порожнє.
 * Довідник /stores/cities таких філій не повертає, тому без окремого варіанта
 * вони не потрапляли в жоден пункт фільтра (STL-02).
 * Мусить збігатися з BranchCriteria::CITY_NONE у store-service.
 */
export const NO_CITY = '__none__';

/** GET /stores/cities: довідник міст + скільки філій він НЕ покриває. */
export interface CityFilter {
  readonly items: readonly CityOption[];
  readonly withoutCity: number;
}

/** PATCH /api/admin/v1/stores/{storeId}: лише YMS-поля, MCP-поля read-only. */
export interface StoreGeneralPatch {
  readonly displayName: string | null;
  readonly phone: string | null;
  readonly addressOverride: string | null;
  readonly ymsStatus: YmsStatus;
  readonly visibleToSuppliers: boolean;
}

// ---------------------------------------------------------------------------
// Постачальники (partner-service)
// ---------------------------------------------------------------------------

export type SupplierStatus = 'active' | 'suspended';
export const SUPPLIER_STATUSES: readonly SupplierStatus[] = ['active', 'suspended'];

export interface SupplierContact {
  readonly name: string;
  readonly phone: string | null;
  readonly email: string | null;
}

/** SUP-03: «всі магазини» або whitelist філій. */
export interface SupplierStoreAccess {
  readonly allStores: boolean;
  readonly storeIds: readonly string[];
}

export interface Supplier {
  readonly id: string;
  readonly name: string;
  readonly edrpou: string | null;
  readonly status: SupplierStatus;
  readonly statusLabel: string;
  readonly storeAccess: SupplierStoreAccess;
  readonly contacts: readonly SupplierContact[];
  readonly suspendedAt: string | null;
  readonly suspendReason: string | null;
  readonly createdAt: string | null;
  readonly updatedAt: string | null;
}

// ---------------------------------------------------------------------------
// Синхронізація MCP (store-service, 5.6)
// ---------------------------------------------------------------------------

export type SyncStatus = 'running' | 'success' | 'partial' | 'failed';
export type SyncTrigger = 'cron' | 'manual' | 'import';

/** Рядок журналу: GET /api/admin/v1/sync/log. */
export interface SyncLogEntry {
  readonly id: string;
  readonly status: SyncStatus;
  readonly statusLabel: string;
  readonly trigger: SyncTrigger;
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

export interface SyncLog {
  readonly items: readonly SyncLogEntry[];
  readonly total: number;
  readonly page: number;
  readonly perPage: number;
  /** INT-13: банер «Останню синхронізацію не завершено, дані станом на …». */
  readonly lastSuccessfulAt: string | null;
  readonly running: boolean;
}

/** Звіт разового запуску: POST /api/admin/v1/sync/run. Ідентифікатора не має. */
export interface SyncReport {
  readonly status: SyncStatus;
  readonly trigger: SyncTrigger;
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

// ---------------------------------------------------------------------------
// Аналітика (analytics-service)
// ---------------------------------------------------------------------------

export interface AnalyticsFilter {
  /** YYYY-MM-DD, локальна дата магазину */
  readonly from: string;
  readonly to: string;
  readonly cities: readonly string[];
  readonly storeIds: readonly string[];
  readonly supplierIds: readonly string[];
}

/** Розрізи KPI-05, значення enum Dimension бекенду. */
export type AnalyticsDimension =
  | 'network'
  | 'city'
  | 'store'
  | 'ramp'
  | 'supplier'
  | 'day'
  | 'week'
  | 'month'
  | 'type'
  | 'rejection_reason';

export const ANALYTICS_DIMENSIONS: readonly AnalyticsDimension[] = [
  'network',
  'city',
  'store',
  'ramp',
  'supplier',
  'day',
  'week',
  'month',
  'type',
  'rejection_reason',
];

export interface UtilizationResult {
  readonly bookedMinutes: number;
  readonly availableMinutes: number;
  readonly utilizationPercent: number;
  readonly slotsCounted: number;
}

export interface OnTimeDeliveryResult {
  readonly onTimeCount: number;
  readonly totalCount: number;
  readonly onTimePercent: number;
  readonly earlyCount: number;
  readonly lateCount: number;
  readonly withoutArrivalCount: number;
}

export interface DurationStatsResult {
  readonly averageMinutes: number;
  readonly medianMinutes: number;
  readonly sampleSize: number;
}

export interface UnloadingTimeResult extends DurationStatsResult {
  readonly averageSlotMinutes: number;
}

export interface NoShowRateResult {
  readonly noShowCount: number;
  readonly totalCount: number;
  readonly noShowPercent: number;
  readonly cancelledExcluded: number;
}

export interface BookingCounters {
  readonly total: number;
  readonly byStatus: Readonly<Record<string, number>>;
  readonly byType: Readonly<Record<string, number>>;
  readonly byRejectionReason: Readonly<Record<string, number>>;
  readonly byDelayReason: Readonly<Record<string, number>>;
  readonly delayedCount: number;
  readonly partialUnloadCount: number;
  readonly plannedPallets: number;
  readonly unloadedPallets: number;
}

export interface KpiTargets {
  readonly utilizationPercent: number;
  readonly onTimePercent: number;
  readonly medianWaitingMinutes: number;
  readonly noShowPercent: number;
}

/** KpiSummary::toArray() analytics-service. */
export interface KpiSummary {
  readonly kpi01_rampUtilization: UtilizationResult;
  readonly kpi02_onTimeDelivery: OnTimeDeliveryResult;
  readonly kpi03_waitingTime: DurationStatsResult;
  readonly kpi04_noShowRate: NoShowRateResult;
  readonly anl04_unloadingTime: UnloadingTimeResult;
  readonly counters: BookingCounters;
  readonly targets: KpiTargets;
}

/** Спільний «хвіст» усіх відповідей аналітики (ANL-13, ANL-14). */
export interface AnalyticsEnvelope {
  readonly filters: string;
  readonly recalculatedAt: string | null;
  readonly empty: boolean;
  readonly message: string | null;
}

export interface AnalyticsKpi extends AnalyticsEnvelope {
  readonly kpi: KpiSummary;
}

export interface BreakdownRow {
  readonly dimension: AnalyticsDimension;
  readonly key: string;
  readonly kpi: KpiSummary;
}

export interface AnalyticsBreakdown extends AnalyticsEnvelope {
  readonly dimension: AnalyticsDimension;
  readonly dimensionLabel: string;
  readonly rows: readonly BreakdownRow[];
}

/** ANL-11: набори даних експорту CSV. */
export type AnalyticsExportDataset = 'bookings' | 'breakdown';
