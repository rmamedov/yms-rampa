import { HttpErrorResponse } from '@angular/common/http';

/** RFC 7807 application/problem+json з розширеннями code / requestId (RBAC-33). */
export interface ProblemDetails {
  readonly type?: string;
  readonly title?: string;
  readonly status?: number;
  readonly detail?: string;
  readonly instance?: string;
  readonly code?: string;
  readonly requestId?: string;
}

/** Коди, на які UI реагує окремими сценаріями. */
export const KNOWN_ERROR_CODES = [
  'SLOT_ALREADY_BOOKED',
  'SLOT_HELD',
  'VEHICLE_TOO_HEAVY',
  'DATE_OUT_OF_HORIZON',
  'BOOKING_LIMIT_EXCEEDED',
  'AUTH_TOKEN_INVALID',
  'RBAC_PERMISSION_DENIED',
  'RBAC_SCOPE_VIOLATION',
  'RBAC_ROLE_ASSIGNMENT_FORBIDDEN',
  'RBAC_SELF_ROLE_CHANGE_FORBIDDEN',
  'RBAC_MULTIPLE_ROLES_FORBIDDEN',
  'RBAC_LAST_SUPER_ADMIN',
  'RESOURCE_NOT_FOUND',
  'SYNC_ALREADY_RUNNING',
  'STORE_NOT_CONFIGURED',
] as const;

export type KnownErrorCode = (typeof KNOWN_ERROR_CODES)[number];

export function isKnownErrorCode(code: string | undefined): code is KnownErrorCode {
  return !!code && (KNOWN_ERROR_CODES as readonly string[]).includes(code);
}

/** Помилка застосунку з розібраним problem+json. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly problem: ProblemDetails,
  ) {
    super(problem.detail ?? problem.title ?? 'error.unknown');
    this.name = 'ApiError';
  }

  get code(): string | undefined {
    return this.problem.code;
  }
}

export function parseProblem(error: unknown): ApiError {
  if (error instanceof ApiError) {
    return error;
  }
  if (error instanceof HttpErrorResponse) {
    const body = error.error as unknown;
    if (body && typeof body === 'object' && !(body instanceof ProgressEvent)) {
      const problem = body as ProblemDetails;
      return new ApiError(error.status || 0, {
        ...problem,
        status: problem.status ?? error.status,
      });
    }
    return new ApiError(error.status || 0, {
      status: error.status,
      detail: error.status === 0 ? undefined : error.message,
    });
  }
  return new ApiError(0, {});
}

/**
 * UI-04: серверні помилки показуються toast-повідомленням українською.
 * Відомі коди мають власні тексти зі словника, інакше — detail з problem+json.
 */
export function problemMessageKeyOrText(error: ApiError): {
  key?: string;
  text?: string;
} {
  if (isKnownErrorCode(error.problem.code)) {
    return { key: `error.${error.problem.code}` };
  }
  if (error.status === 0) {
    return { key: 'error.network' };
  }
  if (error.problem.detail) {
    return { text: error.problem.detail };
  }
  return { key: 'error.unknown' };
}
