/**
 * Моделі даних admin-web (SRS розділи 4 та 5).
 * Дати/часи: у моделях завжди UTC ISO 8601 (ADM-03), локальні часи прийому — HH:MM Europe/Kyiv.
 */

// ---------------------------------------------------------------------------
// Загальне
// ---------------------------------------------------------------------------

export type SortDirection = 'asc' | 'desc';

export interface PageQuery {
  readonly page: number;
  readonly pageSize: PageSize;
  readonly sort?: string;
  readonly direction?: SortDirection;
}

export type PageSize = 20 | 50 | 100;
export const PAGE_SIZES: readonly PageSize[] = [20, 50, 100];

export interface Page<T> {
  readonly items: readonly T[];
  readonly total: number;
  readonly page: number;
  readonly pageSize: number;
}

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
// RBAC (розділ 4)
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
  /** Скоуп магазинів; порожній масив = нуль доступу (RBAC-13). */
  readonly storeIds: readonly string[];
}

export interface AuthTokens {
  readonly accessToken: string;
  readonly refreshToken: string;
  /** UTC ISO 8601 */
  readonly expiresAt: string;
}

export interface AuthSession {
  readonly user: AuthUser;
  readonly tokens: AuthTokens;
}

// ---------------------------------------------------------------------------
// Магазини (5.2, 5.3)
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

/** Поля з MCP — read-only (STC-01). */
export interface McpBranch {
  readonly branchId: string;
  readonly companyId: string;
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: string;
  readonly longitude: string;
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

export interface CalendarException {
  readonly id: string;
  /** YYYY-MM-DD, локальна дата магазину */
  readonly date: string;
  readonly type: ExceptionType;
  readonly intervals: readonly TimeInterval[];
  readonly reason: string;
}

export interface Ramp {
  readonly id: string;
  readonly number: number;
  readonly name: string | null;
  readonly enabled: boolean;
  /** YYYY-MM-DD — з якої дати рампа вимкнена (STC-22) */
  readonly disabledFrom: string | null;
  /** Чи є хоч одне бронювання по рампі (STC-22: видалити не можна). */
  readonly hasBookings: boolean;
}

export interface ReservedSlotRule {
  readonly id: string;
  readonly supplierId: string;
  /** рівно одне з dayOfWeek / date (STC-40) */
  readonly dayOfWeek: DayOfWeek | null;
  readonly date: string | null;
  readonly slotStartTime: string;
  readonly rampId: string;
  readonly validFrom: string;
  readonly validTo: string | null;
  readonly active: boolean;
}

export interface SlotBlock {
  readonly id: string;
  readonly date: string;
  readonly from: string;
  readonly to: string;
  /** порожній масив = усі рампи */
  readonly rampIds: readonly string[];
  readonly reason: string;
  readonly active: boolean;
  readonly createdAt: string;
}

export interface StoreConfig {
  readonly slotSizeMinutes: SlotSizeMinutes | null;
  readonly ramps: readonly Ramp[];
  readonly maxVehicleWeightTons: number | null;
  /** Мінімальний лід-тайм у годинах до початку слоту */
  readonly leadTimeHours: number;
  /** Горизонт бронювання у днях */
  readonly bookingHorizonDays: number;
  readonly receivingWindows: readonly ReceivingWindow[];
  readonly exceptions: readonly CalendarException[];
  readonly reservedRules: readonly ReservedSlotRule[];
  readonly slotBlocks: readonly SlotBlock[];
}

export interface Store extends McpBranch, StoreConfig {
  readonly id: string;
  readonly displayName: string;
  readonly phone: string | null;
  readonly addressOverride: string | null;
  readonly ymsStatus: YmsStatus;
  readonly visibleToSuppliers: boolean;
  /** Обчислюється store-service (STL-04). */
  readonly isConfigured: boolean;
  readonly lastSyncedAt: string;
  readonly missingSyncCount: number;
}

/** Рядок таблиці списку магазинів (STL-01). */
export interface StoreListRow {
  readonly id: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
  readonly ymsStatus: YmsStatus;
  readonly isConfigured: boolean;
  readonly rampCount: number;
  readonly maxVehicleWeightTons: number | null;
  readonly lastSyncedAt: string;
  readonly visibleToSuppliers: boolean;
}

export interface StoreListFilter {
  readonly search: string;
  readonly cities: readonly string[];
  readonly statuses: readonly YmsStatus[];
  /** null = будь-який */
  readonly configured: boolean | null;
}

export interface StoreGeneralPatch {
  readonly displayName: string;
  readonly phone: string | null;
  readonly addressOverride: string | null;
  readonly ymsStatus: YmsStatus;
  readonly visibleToSuppliers: boolean;
}

// ---------------------------------------------------------------------------
// Бронювання і конфлікти (5.3.7)
// ---------------------------------------------------------------------------

export type BookingStatus =
  | 'booked'
  | 'arrived'
  | 'unloading'
  | 'completed'
  | 'cancelled'
  | 'no_show'
  | 'rejected';

export interface Booking {
  readonly id: string;
  readonly storeId: string;
  readonly supplierId: string;
  readonly supplierName: string;
  readonly supplierPhone: string;
  /** YYYY-MM-DD, локальна дата магазину */
  readonly date: string;
  /** HH:MM */
  readonly startTime: string;
  readonly rampId: string;
  readonly vehiclePlate: string;
  readonly vehicleWeightTons: number;
  readonly orderId: string;
  readonly status: BookingStatus;
}

export type ConflictReason =
  | 'no_window'
  | 'slot_grid_shift'
  | 'ramp_disabled'
  | 'weight_limit'
  | 'blocked_range'
  | 'reserved_for_other'
  | 'store_paused';

export type ConflictResolution = 'keep' | 'cancel_notify' | 'manual';

export interface ConfigConflict {
  readonly id: string;
  readonly booking: Booking;
  readonly reason: ConflictReason;
  readonly rampLabel: string;
}

export interface ConflictDecision {
  readonly conflictId: string;
  readonly resolution: ConflictResolution;
}

/** Зміна конфігурації «з дати X» (STC-60). */
export interface ConfigChangeRequest {
  readonly storeId: string;
  /** YYYY-MM-DD — дата набрання чинності */
  readonly effectiveFrom: string;
  readonly config: Partial<StoreConfig>;
  readonly general?: StoreGeneralPatch;
  readonly decisions?: readonly ConflictDecision[];
}

// ---------------------------------------------------------------------------
// Постачальники (5.4)
// ---------------------------------------------------------------------------

export type SupplierStatus = 'active' | 'suspended';
export type StoreAccessMode = 'all' | 'whitelist';

export interface Supplier {
  readonly id: string;
  readonly name: string;
  readonly edrpou: string;
  readonly contactPerson: string;
  readonly contactPhone: string;
  readonly contactEmail: string;
  readonly status: SupplierStatus;
  readonly storeAccessMode: StoreAccessMode;
  readonly allowedStoreIds: readonly string[];
  readonly bookingsCount: number;
}

export type SupplierUserRole = 'supplier_admin' | 'supplier_operator';

export interface SupplierUser {
  readonly id: string;
  readonly supplierId: string;
  readonly fullName: string;
  readonly email: string;
  readonly phone: string;
  readonly role: SupplierUserRole;
  readonly active: boolean;
}

export interface Vehicle {
  readonly id: string;
  readonly supplierId: string;
  readonly plate: string;
  readonly model: string;
  readonly weightTons: number;
}

export interface SupplierDriver {
  readonly id: string;
  readonly supplierId: string;
  readonly fullName: string;
  readonly phone: string;
  readonly active: boolean;
}

// ---------------------------------------------------------------------------
// Staff-користувачі (5.5)
// ---------------------------------------------------------------------------

export interface StaffUser {
  readonly id: string;
  readonly fullName: string;
  readonly email: string;
  readonly phone: string;
  readonly role: StaffRole;
  readonly storeIds: readonly string[];
  readonly active: boolean;
}

// ---------------------------------------------------------------------------
// Синхронізація MCP (5.6)
// ---------------------------------------------------------------------------

export type SyncRunType = 'auto' | 'manual';
export type SyncRunStatus = 'success' | 'error' | 'running';

export interface SyncDiffCreated {
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
}

export interface SyncDiffChanged {
  readonly externalId: string;
  readonly city: string;
  readonly changes: readonly FieldChange[];
}

export interface SyncDiffMissing {
  readonly externalId: string;
  readonly city: string;
  readonly address: string;
  readonly missingSyncCount: number;
  readonly hasFutureBookings: boolean;
}

export interface SyncDiff {
  readonly created: readonly SyncDiffCreated[];
  readonly changed: readonly SyncDiffChanged[];
  readonly missing: readonly SyncDiffMissing[];
}

export interface SyncRun {
  readonly id: string;
  readonly startedAt: string;
  readonly finishedAt: string | null;
  readonly durationMs: number;
  readonly type: SyncRunType;
  readonly initiatedBy: string | null;
  readonly status: SyncRunStatus;
  readonly error: string | null;
  readonly newCount: number;
  readonly changedCount: number;
  readonly missingCount: number;
  readonly diff: SyncDiff;
}

// ---------------------------------------------------------------------------
// Аналітика (5.7)
// ---------------------------------------------------------------------------

export interface AnalyticsFilter {
  /** YYYY-MM-DD */
  readonly from: string;
  readonly to: string;
  readonly cities: readonly string[];
  readonly storeIds: readonly string[];
  readonly supplierIds: readonly string[];
}

export interface UtilizationRow {
  readonly storeId: string;
  readonly storeName: string;
  readonly city: string;
  readonly bookedSlotMinutes: number;
  readonly availableSlotMinutes: number;
  readonly utilization: number;
}

export interface SupplierDeliveryRow {
  readonly supplierId: string;
  readonly supplierName: string;
  readonly booked: number;
  readonly completed: number;
  readonly cancelled: number;
  readonly noShow: number;
}

export interface NoShowRow {
  readonly supplierId: string;
  readonly supplierName: string;
  readonly storeName: string;
  readonly noShow: number;
  readonly total: number;
  readonly share: number;
}

export interface UnloadingTimeRow {
  readonly storeId: string;
  readonly storeName: string;
  readonly avgMinutes: number;
  readonly medianMinutes: number;
  readonly slotSizeMinutes: number;
}

export interface DelayRow {
  readonly storeName: string;
  readonly supplierName: string;
  readonly delayed: number;
  readonly reason: string;
}

export interface AnalyticsDashboard {
  readonly recalculatedAt: string;
  readonly utilization: readonly UtilizationRow[];
  readonly deliveries: readonly SupplierDeliveryRow[];
  readonly noShow: readonly NoShowRow[];
  readonly unloading: readonly UnloadingTimeRow[];
  readonly delays: readonly DelayRow[];
}

export type AnalyticsWidgetId =
  | 'utilization'
  | 'deliveries'
  | 'noShow'
  | 'unloading'
  | 'delays';

// ---------------------------------------------------------------------------
// Аудит (5.8)
// ---------------------------------------------------------------------------

export type AuditAction =
  | 'create'
  | 'update'
  | 'delete'
  | 'status_change'
  | 'sync_run'
  | 'export'
  | 'conflict_resolve';

export type AuditObjectType =
  | 'store'
  | 'supplier'
  | 'staff_user'
  | 'supplier_user'
  | 'sync'
  | 'analytics'
  | 'slot_block'
  | 'reserved_rule';

export interface AuditEntry {
  readonly id: string;
  /** UTC ISO 8601 */
  readonly at: string;
  readonly userId: string;
  readonly userName: string;
  readonly role: StaffRole;
  readonly ip: string;
  readonly objectType: AuditObjectType;
  readonly objectId: string;
  readonly objectLabel: string;
  readonly action: AuditAction;
  readonly changes: readonly FieldChange[];
}

export interface AuditFilter {
  readonly userId: string | null;
  readonly objectType: AuditObjectType | null;
  readonly action: AuditAction | null;
  readonly from: string | null;
  readonly to: string | null;
}
