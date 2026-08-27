/**
 * Моделі домену бронювання (розділ 10.3.1 SRS) у розрізі, потрібному store-web.
 */

export type BookingType = 'scheduled' | 'walk_in';

export type BookingStatus =
  | 'booked'
  | 'arrived'
  | 'unloading'
  | 'completed'
  | 'cancelled'
  | 'no_show'
  | 'rejected';

/** Причини відмови в прийомі (STW-35). */
export type RejectReason =
  | 'weight_exceeded'
  | 'cargo_mismatch'
  | 'missing_documents'
  | 'other';

export const REJECT_REASONS: readonly RejectReason[] = [
  'weight_exceeded',
  'cargo_mismatch',
  'missing_documents',
  'other',
];

/** Причини часткового розвантаження (STW-36). */
export type PartialUnloadReason =
  | 'no_space'
  | 'damaged'
  | 'order_mismatch'
  | 'partial_refusal'
  | 'other';

export const PARTIAL_UNLOAD_REASONS: readonly PartialUnloadReason[] = [
  'no_space',
  'damaged',
  'order_mismatch',
  'partial_refusal',
  'other',
];

/** Довідник причин затримки (STW-19). */
export type DelayReason = 'ramp_busy' | 'previous_vehicle' | 'technical';

export const DELAY_REASONS: readonly DelayReason[] = [
  'ramp_busy',
  'previous_vehicle',
  'technical',
];

export interface Vehicle {
  readonly plateNumber: string;
  readonly weightTons: number;
  readonly brand: string | null;
}

export interface DriverInfo {
  readonly driverId: string;
  readonly fullName: string;
  readonly phone: string;
}

export interface DelayedFlag {
  readonly flag: boolean;
  readonly reason: DelayReason | null;
  /** ISO 8601 UTC */
  readonly eta: string | null;
  readonly comment?: string | null;
}

export interface RejectionInfo {
  readonly at: string;
  readonly by: string;
  readonly reason: RejectReason;
  readonly comment: string | null;
}

export interface PartialUnloadInfo {
  readonly flag: boolean;
  readonly reason: PartialUnloadReason;
  readonly comment?: string | null;
}

export interface Booking {
  readonly id: string;
  readonly type: BookingType;
  readonly storeId: string;
  readonly rampId: string;
  /** ISO 8601 UTC */
  readonly slotStart: string;
  /** ISO 8601 UTC */
  readonly slotEnd: string;
  readonly supplierId: string | null;
  readonly supplierNameSnapshot: string;
  readonly vehicle: Vehicle;
  readonly driver: DriverInfo | null;
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly status: BookingStatus;
  readonly delayed: DelayedFlag;
  readonly arrivedAt: string | null;
  readonly unloadingStartedAt: string | null;
  readonly completedAt: string | null;
  readonly cancelledAt: string | null;
  readonly rejectedAt: RejectionInfo | null;
  readonly unloadedPalletsCount: number | null;
  readonly partialUnload: PartialUnloadInfo | null;
  /** Версія для оптимістичного контролю гонок (STW-17). */
  readonly version: number;
  readonly updatedAt: string;
}

/** Актор журналу дій (STW-33). */
export type AuditActorKind =
  | 'staff'
  | 'driver'
  | 'supplier'
  | 'system_cron'
  | 'admin';

export type AuditActionType =
  | 'status_changed'
  | 'delay_set'
  | 'delay_updated'
  | 'delay_cleared'
  | 'ramp_reassigned'
  | 'created'
  | 'rejected'
  | 'unload_recorded'
  | 'slot_blocked';

export interface AuditEntry {
  readonly id: string;
  readonly bookingId: string;
  /** ISO 8601 UTC */
  readonly at: string;
  readonly actorKind: AuditActorKind;
  /** ПІБ + роль співробітника, або «водій»/«постачальник»/system-cron. */
  readonly actorName: string;
  readonly actorRole: string | null;
  readonly action: AuditActionType;
  readonly fromValue: string | null;
  readonly toValue: string | null;
  readonly comment: string | null;
}

export interface CompleteUnloadingPayload {
  readonly unloadedPalletsCount: number;
  readonly partialUnload: boolean;
  readonly partialUnloadReason: PartialUnloadReason | null;
  readonly partialUnloadComment: string | null;
}

export interface RejectPayload {
  readonly reason: RejectReason;
  readonly comment: string | null;
}

export interface DelayPayload {
  readonly reason: DelayReason;
  readonly comment: string | null;
  /** ISO 8601 UTC */
  readonly eta: string;
}

export interface WalkInPayload {
  readonly supplierId: string | null;
  /** Назва «поза системою», якщо supplierId відсутній. */
  readonly externalSupplierName: string | null;
  readonly plateNumber: string;
  readonly weightTons: number;
  readonly palletsCount: number;
  readonly orderId: string | null;
  readonly rampId: string;
  readonly slotStart: string;
}

export interface ReassignPayload {
  readonly rampId: string;
}
