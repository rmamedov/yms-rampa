/**
 * RFC 7807 application/problem+json (API-02, API-03).
 */

export interface Violation {
  readonly field: string;
  readonly code: string;
  readonly message: string;
}

export interface ProblemDetails {
  readonly type?: string;
  readonly title?: string;
  readonly status?: number;
  readonly detail?: string;
  readonly instance?: string;
  /** Машиночитний код помилки. */
  readonly code?: string;
  readonly requestId?: string;
  readonly violations?: readonly Violation[];
}

/**
 * Коди, які клієнт розрізняє окремо. Перелік звірено з бекендом:
 * AuthException::errorCode() (identity-partner-service),
 * DomainProblem::errorCode() (booking-service) і ROUTE_NOT_FOUND від
 * api-gateway. NETWORK_UNAVAILABLE — суто клієнтський код офлайну.
 */
export const KNOWN_PROBLEM_CODES = [
  'AUTH_INVALID_CREDENTIALS',
  'AUTH_ACCOUNT_LOCKED',
  'AUTH_ACCOUNT_DISABLED',
  'AUTH_ROLE_NOT_ALLOWED',
  'AUTH_TOKEN_INVALID',
  'AUTH_TOKEN_EXPIRED',
  'AUTH_REFRESH_REUSED',
  'PARTNER_LOGIN_INVALID',
  'VALIDATION_FAILED',
  'ACCESS_DENIED',
  'BOOKING_NOT_FOUND',
  'NOT_FOUND',
  'ROUTE_NOT_FOUND',
  'UPSTREAM_UNAVAILABLE',
  'INTERNAL_ERROR',
  'NETWORK_UNAVAILABLE',
] as const;

export type KnownProblemCode = (typeof KNOWN_PROBLEM_CODES)[number];

/** Помилка, кинута HTTP-шаром після розбору problem+json. */
export class ApiProblemError extends Error {
  constructor(
    readonly status: number,
    readonly problem: ProblemDetails,
  ) {
    super(problem.detail ?? problem.title ?? `HTTP ${status}`);
    this.name = 'ApiProblemError';
  }

  /** Нормалізований код у ВЕРХНЬОМУ регістрі (сервер може віддавати snake_case). */
  get code(): string {
    return (this.problem.code ?? '').toUpperCase();
  }

  is(code: KnownProblemCode): boolean {
    return this.code === code;
  }
}

/** Розпізнає problem+json у довільному тілі відповіді. */
export function toProblem(status: number, body: unknown): ProblemDetails {
  if (body && typeof body === 'object') {
    const raw = body as Record<string, unknown>;
    return {
      type: typeof raw['type'] === 'string' ? raw['type'] : undefined,
      title: typeof raw['title'] === 'string' ? raw['title'] : undefined,
      status: typeof raw['status'] === 'number' ? raw['status'] : status,
      detail: typeof raw['detail'] === 'string' ? raw['detail'] : undefined,
      instance:
        typeof raw['instance'] === 'string' ? raw['instance'] : undefined,
      code: typeof raw['code'] === 'string' ? raw['code'] : undefined,
      requestId:
        typeof raw['requestId'] === 'string' ? raw['requestId'] : undefined,
      violations: Array.isArray(raw['violations'])
        ? (raw['violations'] as Violation[])
        : undefined,
    };
  }
  return { status };
}
