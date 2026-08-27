/**
 * DTO рівно у формі JSON реального бекенду YMS «Рампа».
 *
 * Джерела істини:
 *  - booking-service `App\Infrastructure\Http\BookingPresenter::toArray()`;
 *  - booking-service `App\Controller\Store\BookingActionController` / `WalkInController`
 *    (тіла запитів, розібрані через `RequestPayload`);
 *  - identity-staff-service `App\Controller\AuthController::tokenResponse()`
 *    і `App\Domain\Auth\LoginResult::profile()`.
 *
 * Нічого, крім цього файлу, не має знати про формат дроту: мок-бекенд віддає
 * саме ці структури, HTTP-шлюз — теж, а `wire.mapper.ts` перетворює їх на
 * доменні моделі застосунку.
 */

// ---------------------------------------------------------------------------
// booking-service
// ---------------------------------------------------------------------------

/**
 * `StatusChange::toArray()` — append-only журнал переходів (DATA-14).
 *
 * `byRole` / `byContour` / `byLabel` бекенд додає поруч із `by`: сам `by` —
 * це UUID облікового запису. Усі три поля можуть бути null для записів,
 * зроблених до їх появи.
 */
export interface WireStatusChange {
  readonly from: string | null;
  readonly to: string;
  readonly at: string;
  readonly by: string;
  readonly byRole?: string | null;
  readonly byContour?: string | null;
  readonly byLabel?: string | null;
  readonly meta?: Record<string, unknown>;
}

/** `StoreSnapshot::toArray()` — снапшот філії на момент бронювання (DATA-13). */
export interface WireStoreSnapshot {
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
}

/** `VehicleSnapshot::toArray()`. */
export interface WireVehicle {
  readonly plateNumber: string;
  readonly weightTons: number;
  readonly brand: string | null;
}

/**
 * `DelayInfo::toArray()`. `reason` — вільний текст: бекенд для причини «інше»
 * склеює її з коментарем у вигляді «інше: <текст>», окремого поля comment немає.
 */
export interface WireDelayInfo {
  readonly flag: boolean;
  readonly reason: string | null;
  readonly eta: string | null;
}

/** `Rejection::toArray()` — віддається в полі `rejectedAt`. */
export interface WireRejection {
  readonly at: string;
  readonly by: string;
  readonly reason: string;
  readonly comment: string | null;
}

/** `PartialUnload::toArray()`. */
export interface WirePartialUnload {
  readonly flag: boolean;
  readonly reason: string;
  readonly comment: string | null;
}

/** `Cancellation::toArray()`. */
export interface WireCancellation {
  readonly by: string;
  readonly userId: string | null;
  readonly reason: string | null;
}

/**
 * `DriverInfo::toArray()` — знімок профілю водія поруч із `driverId`.
 * Поля `driver` може не бути взагалі (водія не призначено, профіль недоступний).
 */
export interface WireDriver {
  readonly driverId: string;
  readonly fullName: string;
  readonly phone: string | null;
  readonly active: boolean;
}

/** Повна відповідь `BookingPresenter::toArray()`. */
export interface WireBooking {
  readonly id: string;
  readonly type: string;
  readonly status: string;
  readonly storeId: string;
  readonly store: WireStoreSnapshot;
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly localDate: string;
  readonly localTime: string;
  readonly supplierId: string | null;
  readonly supplierName: string | null;
  readonly vehicle: WireVehicle;
  readonly driverId: string | null;
  readonly driver?: WireDriver | null;
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly delayed: WireDelayInfo;
  readonly arrivedAt: string | null;
  readonly unloadingStartedAt: string | null;
  readonly completedAt: string | null;
  readonly cancelledAt: string | null;
  readonly cancellation: WireCancellation | null;
  readonly rejectedAt: WireRejection | null;
  readonly unloadedPalletsCount: number | null;
  readonly partialUnload: WirePartialUnload | null;
  readonly rescheduleOf: string | null;
  readonly routeSheetId: string | null;
  readonly createdBy: string;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly statusHistory: readonly WireStatusChange[];
}

// --- Читання контуру магазину ---------------------------------------------
//
// Джерело істини — booking-service `App\Controller\Store\StoreReadController`
// і презентери поруч із ним. Колекції приходять ПЛОСКИМ масивом, без обгортки
// `{items}` і без пагінації: перелік філій, рамп і постачальників мережі — це
// десятки записів, які віддаються цілком. Виняток — дошка: їй потрібен
// серверний `now`, тому вона обʼєкт.

/** `StoreBrief::toArray()` — філія в переліку GET /stores. */
export interface WireStoreBrief {
  readonly storeId: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
  /** Статус філії в YMS; у переліку буває лише `active`. */
  readonly ymsStatus: string;
}

/** Рампа у конфігурації філії. */
export interface WireRamp {
  readonly rampId: string;
  readonly name: string;
  readonly active: boolean;
}

export interface WireReceivingInterval {
  readonly from: string;
  readonly to: string;
}

export interface WireReceivingWindow {
  /** 1 — понеділок … 7 — неділя. */
  readonly dayOfWeek: number;
  readonly intervals: readonly WireReceivingInterval[];
}

/** `StoreConfigPresenter::toArray()` — GET /stores/{storeId}/config. */
export interface WireStoreConfig {
  readonly storeId: string;
  readonly externalId: string;
  readonly displayName: string;
  readonly city: string;
  readonly address: string;
  readonly ramps: readonly WireRamp[];
  readonly slotSizeMinutes: number;
  readonly receivingWindows: readonly WireReceivingWindow[];
  readonly maxVehicleWeightTons: number;
  readonly noShowGraceMinutes: number;
  readonly leadTimeMinutes: number;
  readonly horizonDays: number;
}

/** Постачальник у довіднику GET /stores/{storeId}/suppliers. */
export interface WireSupplierRef {
  readonly supplierId: string;
  readonly name: string;
}

/**
 * `StaffSlotPresenter::slots()` — клітинка сітки очима персоналу.
 * `reservedForSupplierId` і `blockReason` присутні лише тоді, коли мають значення.
 */
export interface WireSlot {
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly localStart: string;
  readonly state: string;
  readonly selectable: boolean;
  readonly bookingId: string | null;
  readonly reservedForSupplierId?: string | null;
  readonly blockReason?: string | null;
}

/** `StaffSlotPresenter::week()` — доба сітки з ключем локальної дати. */
export interface WireWeekDay {
  readonly dateKey: string;
  readonly slots: readonly WireSlot[];
}

/** `StoreBoardPresenter::toArray()` — GET /bookings?storeId=&date=. */
export interface WireStoreBoard {
  readonly storeId: string;
  readonly date: string;
  /** Серверний час зрізу: на ньому тримаються таймери і доступність дій. */
  readonly now: string;
  readonly bookings: readonly WireBooking[];
}

// --- Тіла запитів контуру магазину ----------------------------------------

/** POST /api/store/v1/bookings/{bookingId}/completed */
export interface WireCompleteRequest {
  readonly unloadedPalletsCount: number;
  readonly partialUnload?: {
    readonly reason: string;
    readonly comment: string | null;
  };
}

/** POST /api/store/v1/bookings/{bookingId}/rejected */
export interface WireRejectRequest {
  readonly reason: string;
  readonly comment: string | null;
}

/** POST /api/store/v1/bookings/{bookingId}/delay */
export interface WireDelayRequest {
  readonly reason: string;
  readonly eta: string;
  readonly comment: string | null;
}

/** POST /api/store/v1/bookings/{bookingId}/reassign */
export interface WireReassignRequest {
  readonly rampId: string;
}

/** POST /api/store/v1/bookings/walk-in */
export interface WireWalkInRequest {
  readonly storeId: string;
  readonly rampId: string;
  readonly slotStart: string;
  readonly vehicle: WireVehicle;
  readonly palletsCount: number;
  readonly supplierId: string | null;
  readonly supplierName: string | null;
  readonly orderId: string | null;
}

// ---------------------------------------------------------------------------
// identity-staff-service
// ---------------------------------------------------------------------------

/** `LoginResult::profile()` — поле `user` відповіді логіну/refresh. */
export interface WireStaffUser {
  readonly id: string;
  readonly email: string;
  readonly fullName: string;
  /** RBAC-04: роль в однині. */
  readonly role: string;
  readonly roleLabel: string;
  readonly scope: {
    readonly storeIds: readonly string[];
    /** RBAC-16: доступ до всієї мережі без фільтра за storeIds. */
    readonly networkWide: boolean;
  };
  readonly twoFactorEnabled: boolean;
  readonly permissions: readonly string[];
}

/**
 * `AuthController::tokenResponse()` — ПЛОСКА структура: токени лежать поруч
 * із профілем, обгортки `tokens` немає. Однакова для login і refresh.
 */
export interface WireAuthTokenResponse {
  readonly tokenType: string;
  readonly accessToken: string;
  readonly expiresIn: number;
  readonly accessExpiresAt: string;
  readonly refreshToken: string;
  readonly refreshExpiresAt: string;
  readonly sessionId: string;
  readonly user: WireStaffUser;
}

/** POST /api/store/v1/auth/login — крок 1 (email+пароль) або крок 2 (2FA). */
export interface WireLoginRequest {
  readonly email?: string;
  readonly password?: string;
  readonly challengeToken?: string;
  readonly totpCode?: string;
}
