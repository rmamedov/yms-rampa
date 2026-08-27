/**
 * RFC 7807 application/problem+json — єдиний формат помилок бекенду YMS.
 *
 * Бекенд віддає документ виду
 * {"type":"about:blank","title":"…","status":422,"detail":"…",
 *  "code":"VEHICLE_TOO_HEAVY","requestId":"…", <розширення>}
 * де розширення (maxVehicleWeightTons, limit, horizonDays…) лежать
 * ПОРУЧ зі стандартними членами, а не у вкладеному об'єкті.
 */

export const ERROR_CODES = {
  // booking-service
  slotAlreadyBooked: 'SLOT_ALREADY_BOOKED',
  slotHeld: 'SLOT_HELD',
  slotReserved: 'SLOT_RESERVED',
  slotNotAvailable: 'SLOT_NOT_AVAILABLE',
  holdExpired: 'HOLD_EXPIRED',
  holdNotOwned: 'HOLD_NOT_OWNED',
  vehicleTooHeavy: 'VEHICLE_TOO_HEAVY',
  vehicleTimeConflict: 'VEHICLE_TIME_CONFLICT',
  dateOutOfHorizon: 'DATE_OUT_OF_HORIZON',
  bookingLimitExceeded: 'BOOKING_LIMIT_EXCEEDED',
  palletsOutOfRange: 'PALLETS_OUT_OF_RANGE',
  invalidPlateNumber: 'INVALID_PLATE_NUMBER',
  bookingNotFound: 'BOOKING_NOT_FOUND',
  transitionNotAllowed: 'TRANSITION_NOT_ALLOWED',
  editDeadlinePassed: 'EDIT_DEADLINE_PASSED',
  storeNotFound: 'STORE_NOT_FOUND',
  supplierNotAllowed: 'SUPPLIER_NOT_ALLOWED',
  // partner-service
  vehiclePlateDuplicate: 'VEHICLE_PLATE_DUPLICATE',
  vehicleHasActiveBookings: 'VEHICLE_HAS_ACTIVE_BOOKINGS',
  driverPhoneDuplicate: 'DRIVER_PHONE_DUPLICATE',
  // identity-partner-service
  authInvalidCredentials: 'AUTH_INVALID_CREDENTIALS',
  authAccountLocked: 'AUTH_ACCOUNT_LOCKED',
  authAccountDisabled: 'AUTH_ACCOUNT_DISABLED',
  authTokenExpired: 'AUTH_TOKEN_EXPIRED',
  authTokenInvalid: 'AUTH_TOKEN_INVALID',
  authRefreshReused: 'AUTH_REFRESH_REUSED',
  // спільні
  accessDenied: 'ACCESS_DENIED',
  validationFailed: 'VALIDATION_FAILED',
  notFound: 'NOT_FOUND',
  internalError: 'INTERNAL_ERROR',
  upstreamUnavailable: 'UPSTREAM_UNAVAILABLE',
  // суто клієнтські
  network: 'NETWORK_ERROR',
  unknown: 'UNKNOWN',
} as const;

export type ErrorCode = (typeof ERROR_CODES)[keyof typeof ERROR_CODES];

export interface ApiProblem {
  readonly type: string;
  readonly title: string;
  readonly status: number;
  readonly detail: string;
  readonly code: string;
  readonly requestId?: string;
  /** Розширення problem-документа (maxVehicleWeightTons, limit, horizonDays…). */
  readonly meta?: Readonly<Record<string, unknown>>;
}

export class ApiProblemError extends Error {
  constructor(readonly problem: ApiProblem) {
    super(problem.detail || problem.title);
    this.name = 'ApiProblemError';
  }
}

/** Стандартні члени RFC 7807 + розширення, які бекенд додає завжди. */
const STANDARD_MEMBERS = new Set([
  'type',
  'title',
  'status',
  'detail',
  'instance',
  'code',
  'requestId',
]);

/** Заголовки, які бекенд ставить у problem-документі за статусом. */
export function titleForStatus(status: number): string {
  switch (status) {
    case 400:
      return 'Некоректний запит';
    case 401:
      return 'Не автентифіковано';
    case 403:
      return 'Доступ заборонено';
    case 404:
      return 'Не знайдено';
    case 409:
      return 'Конфлікт';
    case 422:
      return 'Не пройдено валідацію';
    default:
      return status >= 500 ? 'Внутрішня помилка' : 'Помилка запиту';
  }
}

export function problem(
  status: number,
  code: string,
  detail: string,
  meta?: Record<string, unknown>,
): ApiProblem {
  return {
    type: 'about:blank',
    title: titleForStatus(status),
    status,
    detail,
    code,
    meta,
  };
}

export function problemError(
  status: number,
  code: string,
  detail: string,
  meta?: Record<string, unknown>,
): ApiProblemError {
  return new ApiProblemError(problem(status, code, detail, meta));
}

export function isApiProblem(value: unknown): value is ApiProblem {
  if (!value || typeof value !== 'object') {
    return false;
  }
  const candidate = value as Partial<ApiProblem>;
  return (
    typeof candidate.status === 'number' && typeof candidate.title === 'string'
  );
}

/**
 * Нормалізує будь-яку помилку транспорту у problem-документ.
 * HttpErrorResponse свідомо не імпортується — приймаємо структурний тип,
 * щоб функція лишалась чистою і легко тестувалась.
 */
export function toProblem(err: unknown): ApiProblem {
  if (err instanceof ApiProblemError) {
    return err.problem;
  }
  const http = err as {
    status?: number;
    error?: unknown;
    message?: string;
  } | null;

  if (http && isApiProblem(http.error)) {
    const body = http.error as ApiProblem & Record<string, unknown>;
    return {
      type: body.type || 'about:blank',
      title: body.title,
      status: body.status,
      detail: body.detail || body.title,
      code: body.code || codeByStatus(body.status),
      requestId:
        typeof body.requestId === 'string' ? body.requestId : undefined,
      meta: extensionsOf(body),
    };
  }

  const status = typeof http?.status === 'number' ? http.status : 0;
  if (status === 0) {
    return problem(
      0,
      ERROR_CODES.network,
      'Немає звʼязку з сервером. Спробуйте ще раз.',
    );
  }
  return problem(
    status,
    codeByStatus(status),
    http?.message ?? 'Сталася невідома помилка.',
  );
}

/** Нестандартні члени problem-документа — розширення бекенду. */
function extensionsOf(
  body: Record<string, unknown>,
): Record<string, unknown> | undefined {
  const meta: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(body)) {
    if (!STANDARD_MEMBERS.has(key)) {
      meta[key] = value;
    }
  }
  return Object.keys(meta).length > 0 ? meta : undefined;
}

function codeByStatus(status: number): string {
  if (status === 401) {
    return ERROR_CODES.authTokenInvalid;
  }
  if (status === 403) {
    return ERROR_CODES.accessDenied;
  }
  if (status === 404) {
    return ERROR_CODES.notFound;
  }
  if (status >= 500) {
    return ERROR_CODES.internalError;
  }
  return ERROR_CODES.unknown;
}
