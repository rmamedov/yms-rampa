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

/** `StatusChange::toArray()` — append-only журнал переходів (DATA-14). */
export interface WireStatusChange {
  readonly from: string | null;
  readonly to: string;
  readonly at: string;
  readonly by: string;
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
