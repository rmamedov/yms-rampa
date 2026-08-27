/**
 * RFC 7807 application/problem+json — єдиний формат помилок бекенду.
 */

export interface ProblemDetails {
  readonly type?: string;
  readonly title?: string;
  readonly status: number;
  readonly detail?: string;
  readonly instance?: string;
  /** Доменний код помилки — головний перемикач сценаріїв в UI. */
  readonly code?: string;
  readonly [key: string]: unknown;
}

/**
 * Коди, на які UI реагує власним текстом.
 *
 * Перелік звірено з реальними джерелами:
 *  - booking-service `App\Domain\**\*Exception::ERROR_CODE`;
 *  - identity-staff-service `App\Domain\Auth\Exception\*`;
 *  - api-gateway (nginx): ROUTE_NOT_FOUND, AUTH_TOKEN_INVALID.
 */
export const KNOWN_PROBLEM_CODES = [
  // booking-service
  'VALIDATION_FAILED',
  'ACCESS_DENIED',
  'BOOKING_NOT_FOUND',
  'INVALID_STATUS_TRANSITION',
  'TRANSITION_NOT_ALLOWED',
  'SLOT_ALREADY_BOOKED',
  'SLOT_NOT_AVAILABLE',
  'SLOT_RESERVED',
  'SLOT_HELD',
  'VEHICLE_TOO_HEAVY',
  'VEHICLE_TIME_CONFLICT',
  'PALLETS_OUT_OF_RANGE',
  'INVALID_PLATE_NUMBER',
  'SUPPLIER_NOT_ALLOWED',
  'STORE_NOT_FOUND',
  'EDIT_DEADLINE_PASSED',
  'DATE_OUT_OF_HORIZON',
  'BOOKING_LIMIT_EXCEEDED',
  // identity-staff-service + шлюз
  'AUTH_INVALID_CREDENTIALS',
  'AUTH_TOKEN_INVALID',
  'AUTH_TOKEN_EXPIRED',
  'AUTH_REFRESH_REUSED',
  'AUTH_ACCOUNT_DISABLED',
  'AUTH_ACCOUNT_LOCKED',
  'ROUTE_NOT_FOUND',
] as const;

export type KnownProblemCode = (typeof KNOWN_PROBLEM_CODES)[number];

/** Помилка застосунку з уже розібраним problem+json. */
export class AppError extends Error {
  constructor(
    readonly problem: ProblemDetails,
    /** Ключ i18n для відомого коду; null — показуємо problem.detail. */
    readonly messageKey: string | null,
  ) {
    super(problem.detail ?? problem.title ?? 'Помилка');
    this.name = 'AppError';
  }

  get code(): string | undefined {
    return this.problem.code;
  }

  get status(): number {
    return this.problem.status;
  }
}
