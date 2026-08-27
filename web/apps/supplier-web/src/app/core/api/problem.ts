/**
 * RFC 7807 application/problem+json — єдиний формат помилок бекенду YMS.
 */

export const ERROR_CODES = {
  slotAlreadyBooked: 'SLOT_ALREADY_BOOKED',
  slotHeld: 'SLOT_HELD',
  slotReserved: 'SLOT_RESERVED',
  vehicleTooHeavy: 'VEHICLE_TOO_HEAVY',
  dateOutOfHorizon: 'DATE_OUT_OF_HORIZON',
  bookingLimitExceeded: 'BOOKING_LIMIT_EXCEEDED',
  palletsOutOfRange: 'PALLETS_OUT_OF_RANGE',
  slotInPast: 'SLOT_IN_PAST',
  holdExpired: 'HOLD_EXPIRED',
  vehicleTimeConflict: 'VEHICLE_TIME_CONFLICT',
  duplicatePlate: 'DUPLICATE_PLATE',
  driverPhoneTaken: 'DRIVER_PHONE_TAKEN',
  vehicleInUse: 'VEHICLE_IN_USE',
  supplierNotAllowed: 'SUPPLIER_NOT_ALLOWED',
  invalidCredentials: 'INVALID_CREDENTIALS',
  tooManyAttempts: 'TOO_MANY_ATTEMPTS',
  driverAccount: 'DRIVER_ACCOUNT',
  unauthorized: 'UNAUTHORIZED',
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
  /** Додаткові члени problem-документа (напр. maxVehicleWeightTons, limit). */
  readonly meta?: Readonly<Record<string, unknown>>;
}

export class ApiProblemError extends Error {
  constructor(readonly problem: ApiProblem) {
    super(problem.detail || problem.title);
    this.name = 'ApiProblemError';
  }
}

export function problem(
  status: number,
  code: string,
  detail: string,
  meta?: Record<string, unknown>,
): ApiProblem {
  return {
    type: `https://yms.rampa/errors/${code.toLowerCase()}`,
    title: code,
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
    const body = http.error;
    return {
      ...body,
      code: body.code || codeByStatus(body.status),
      detail: body.detail || body.title,
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

function codeByStatus(status: number): string {
  if (status === 401) {
    return ERROR_CODES.unauthorized;
  }
  if (status >= 500) {
    return ERROR_CODES.network;
  }
  return ERROR_CODES.unknown;
}
