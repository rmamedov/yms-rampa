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

/**
 * Коди, на які UI реагує власним текстом. Перелік звірено з бекендом:
 * store-service (NotFoundException/ValidationException/ConflictException),
 * partner-service, identity-staff-service, analytics-service.
 */
export const KNOWN_ERROR_CODES = [
  // спільні / RBAC
  'AUTH_TOKEN_INVALID',
  'AUTH_REFRESH_REUSED',
  'AUTH_INVALID_CREDENTIALS',
  'AUTH_ACCOUNT_DISABLED',
  'RBAC_PERMISSION_DENIED',
  'RBAC_SCOPE_VIOLATION',
  'RESOURCE_NOT_FOUND',
  'VALIDATION_FAILED',
  'INTERNAL_ERROR',
  // store-service
  'STORE_NOT_FOUND',
  'STORE_NOT_CONFIGURED',
  'CONFIG_NOT_FOUND',
  'CONFIG_VALIDATION_FAILED',
  'MCP_FIELD_READ_ONLY',
  'RESERVED_RULE_NOT_FOUND',
  'RESERVED_RULE_OVERLAP',
  'SLOT_BLOCK_NOT_FOUND',
  'SYNC_ALREADY_RUNNING',
  // partner-service
  'SUPPLIER_NOT_FOUND',
  'SUPPLIER_HAS_BOOKINGS',
  'SUPPLIER_STATUS_INVALID',
  // analytics-service
  'ANALYTICS_INVALID_PERIOD',
  'ANALYTICS_PERIOD_TOO_LONG',
  'ANALYTICS_INVALID_DIMENSION',
  'ANALYTICS_INVALID_FILTER',
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
