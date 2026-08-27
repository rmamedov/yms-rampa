/**
 * Моделі домену бронювання у розрізі, потрібному store-web.
 *
 * Форма полів повторює відповідь booking-service
 * (`App\Infrastructure\Http\BookingPresenter::toArray()`) — див. wire.model.ts.
 * Оптимістичної версії (`version`) бекенд НЕ має: конкурентні зміни ловляться
 * доменними переходами (409 INVALID_STATUS_TRANSITION).
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

/**
 * Довідники причин. ЗНАЧЕННЯ — рівно ті рядки, які приймають backed-enum'и
 * booking-service (`RejectionReason`, `PartialUnloadReason`, `DelayReason`):
 * вони україномовні, тому одночасно є й підписами в UI.
 */

/** Спільне значення «інше», для якого коментар обовʼязковий. */
export const REASON_OTHER = 'інше';

/** `App\Domain\Booking\RejectionReason` (ST-07). */
export const REJECT_REASONS = [
  'перевищення тоннажу',
  'невідповідність вантажу',
  'відсутні документи',
  REASON_OTHER,
] as const;
export type RejectReason = (typeof REJECT_REASONS)[number];

/** `App\Domain\Booking\PartialUnloadReason` (ST-03). */
export const PARTIAL_UNLOAD_REASONS = [
  'немає місця',
  'бій/брак',
  'розбіжність із замовленням',
  'відмова частини вантажу',
  REASON_OTHER,
] as const;
export type PartialUnloadReason = (typeof PARTIAL_UNLOAD_REASONS)[number];

/** `App\Domain\Booking\DelayReason` (DLY-01). */
export const DELAY_REASONS = [
  'затори',
  'поломка',
  'затримка на попередній точці',
  REASON_OTHER,
] as const;
export type DelayReason = (typeof DELAY_REASONS)[number];

export interface Vehicle {
  readonly plateNumber: string;
  readonly weightTons: number;
  readonly brand: string | null;
}

/** Снапшот філії всередині бронювання (DATA-13). */
export interface StoreSnapshot {
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
}

/**
 * Прапорець затримки. `reason` — вільний текст бекенду: для причини «інше»
 * він приходить у вигляді «інше: <коментар>», окремого поля comment немає.
 */
export interface DelayedFlag {
  readonly flag: boolean;
  readonly reason: string | null;
  /** ISO 8601 UTC */
  readonly eta: string | null;
}

export interface RejectionInfo {
  readonly at: string;
  readonly by: string;
  readonly reason: string;
  readonly comment: string | null;
}

export interface PartialUnloadInfo {
  readonly flag: boolean;
  readonly reason: string;
  readonly comment: string | null;
}

export interface CancellationInfo {
  readonly by: string;
  readonly userId: string | null;
  readonly reason: string | null;
}

/** Запис журналу переходів (`statusHistory`, DATA-14). */
export interface StatusChange {
  readonly from: BookingStatus | null;
  readonly to: BookingStatus;
  /** ISO 8601 UTC */
  readonly at: string;
  /** userId ініціатора переходу. */
  readonly by: string;
  readonly meta: Readonly<Record<string, unknown>>;
}

export interface Booking {
  readonly id: string;
  readonly type: BookingType;
  readonly status: BookingStatus;
  readonly storeId: string;
  readonly store: StoreSnapshot;
  readonly rampId: string;
  /** ISO 8601 UTC */
  readonly slotStart: string;
  /** ISO 8601 UTC */
  readonly slotEnd: string;
  /** YYYY-MM-DD у таймзоні магазину. */
  readonly localDate: string;
  /** HH:mm у таймзоні магазину. */
  readonly localTime: string;
  readonly supplierId: string | null;
  readonly supplierName: string;
  readonly vehicle: Vehicle;
  /** Бекенд віддає лише ідентифікатор водія — ПІБ і телефон недоступні. */
  readonly driverId: string | null;
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly delayed: DelayedFlag;
  readonly arrivedAt: string | null;
  readonly unloadingStartedAt: string | null;
  readonly completedAt: string | null;
  readonly cancelledAt: string | null;
  readonly cancellation: CancellationInfo | null;
  readonly rejectedAt: RejectionInfo | null;
  readonly unloadedPalletsCount: number | null;
  readonly partialUnload: PartialUnloadInfo | null;
  readonly rescheduleOf: string | null;
  readonly routeSheetId: string | null;
  readonly createdBy: string;
  readonly createdAt: string;
  readonly updatedAt: string;
  /** Джерело журналу дій — окремого ендпоінта аудиту бекенд не має. */
  readonly statusHistory: readonly StatusChange[];
}

// ---------------------------------------------------------------------------
// Тіла запитів (назви полів — рівно як у RequestPayload бекенду)
// ---------------------------------------------------------------------------

/** POST /bookings/{id}/completed */
export interface CompleteUnloadingPayload {
  readonly unloadedPalletsCount: number;
  /** null — розвантажено все заявлене. */
  readonly partialUnload: {
    readonly reason: PartialUnloadReason;
    readonly comment: string | null;
  } | null;
}

/** POST /bookings/{id}/rejected */
export interface RejectPayload {
  readonly reason: RejectReason;
  readonly comment: string | null;
}

/** POST /bookings/{id}/delay */
export interface DelayPayload {
  readonly reason: DelayReason;
  /** ISO 8601 UTC */
  readonly eta: string;
  readonly comment: string | null;
}

/** POST /bookings/{id}/reassign */
export interface ReassignPayload {
  readonly rampId: string;
}

/** POST /bookings/walk-in */
export interface WalkInPayload {
  readonly storeId: string;
  readonly rampId: string;
  /** ISO 8601 UTC */
  readonly slotStart: string;
  readonly vehicle: Vehicle;
  readonly palletsCount: number;
  readonly supplierId: string | null;
  /** Назва «поза системою», якщо supplierId відсутній. */
  readonly supplierName: string | null;
  readonly orderId: string | null;
}
