/** Довідники магазину, рамп та слотів (розділи 6, 10 SRS). */

export interface Ramp {
  readonly rampId: string;
  readonly name: string;
  readonly active: boolean;
}

export interface ReceivingInterval {
  readonly from: string;
  readonly to: string;
}

export interface ReceivingWindow {
  /** 1 — понеділок … 7 — неділя */
  readonly dayOfWeek: number;
  readonly intervals: readonly ReceivingInterval[];
}

export interface StoreConfig {
  readonly storeId: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
  readonly ramps: readonly Ramp[];
  readonly slotSizeMinutes: number;
  readonly receivingWindows: readonly ReceivingWindow[];
  readonly maxVehicleWeightTons: number;
  readonly noShowGraceMinutes: number;
  readonly leadTimeMinutes: number;
  readonly horizonDays: number;
}

/** Обчислюваний стан слота (SLOT-03). */
export type SlotState =
  | 'available'
  | 'held'
  | 'booked'
  | 'reserved'
  | 'blocked'
  | 'past';

export interface Slot {
  readonly rampId: string;
  /** ISO 8601 UTC */
  readonly slotStart: string;
  /** ISO 8601 UTC */
  readonly slotEnd: string;
  /** HH:mm у таймзоні магазину — підпис клітинки сітки. */
  readonly localStart: string;
  readonly state: SlotState;
  /** Чи вільний слот для нового бронювання (для персоналу = state `available`). */
  readonly selectable: boolean;
  /** Бронювання, яке займає клітинку; null — вільна. */
  readonly bookingId: string | null;
  /**
   * Чужі резерви персоналу видно: приховування (GRID-04) стосується лише
   * контуру постачальника.
   */
  readonly reservedForSupplierId: string | null;
  readonly blockReason: string | null;
}

export interface SupplierRef {
  readonly supplierId: string;
  readonly name: string;
}
