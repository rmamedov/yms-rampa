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

/** Відомі коди, на які UI реагує окремо (розділ 9.11 + загальні коди). */
export const KNOWN_PROBLEM_CODES = [
  'SLOT_ALREADY_BOOKED',
  'SLOT_HELD',
  'VEHICLE_TOO_HEAVY',
  'DATE_OUT_OF_HORIZON',
  'BOOKING_LIMIT_EXCEEDED',
  'BOOKING_STATUS_CONFLICT',
  'STORE_FORBIDDEN',
  'NO_SHOW_TOO_EARLY',
  'ETA_BEFORE_SLOT_START',
  'REJECT_REASON_REQUIRED',
  'RAMP_SLOT_TAKEN',
  'SLOT_HAS_ACTIVE_BOOKING',
  'AUTH_INVALID_CREDENTIALS',
  'AUTH_TOKEN_INVALID',
  'FORBIDDEN_ROLE',
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
