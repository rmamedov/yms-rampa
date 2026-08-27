import { HttpErrorResponse } from '@angular/common/http';
import {
  AppError,
  KNOWN_PROBLEM_CODES,
  ProblemDetails,
} from '../models/problem.model';

const KNOWN = new Set<string>(KNOWN_PROBLEM_CODES);

/** Ключ i18n для відомого коду; null — показуємо detail з бекенду. */
export function messageKeyForCode(code: string | undefined): string | null {
  if (!code) return null;
  return KNOWN.has(code) ? `error.${code}` : null;
}

/** Розбирає відповідь у RFC 7807 application/problem+json. */
export function toProblem(error: unknown): ProblemDetails {
  if (error instanceof HttpErrorResponse) {
    const body = error.error as Partial<ProblemDetails> | string | null;
    if (body && typeof body === 'object') {
      return {
        ...body,
        status: typeof body.status === 'number' ? body.status : error.status,
      } as ProblemDetails;
    }
    if (error.status === 0) {
      return { status: 0, code: 'NETWORK_ERROR', title: 'Network error' };
    }
    return {
      status: error.status,
      title: error.statusText,
      detail: typeof body === 'string' ? body : undefined,
    };
  }
  if (error instanceof AppError) {
    return error.problem;
  }
  if (error instanceof Error) {
    return { status: 0, title: error.name, detail: error.message };
  }
  return { status: 0, title: 'Unknown error' };
}

export function toAppError(error: unknown): AppError {
  if (error instanceof AppError) return error;
  const problem = toProblem(error);
  return new AppError(problem, messageKeyForCode(problem.code));
}

/**
 * Текст для користувача: відомий код → ключ i18n, інакше detail з бекенду.
 * Повертає { key } або { text }.
 */
export function describeError(
  error: unknown,
): { key: string; text: null } | { key: null; text: string } {
  const appError = toAppError(error);
  if (appError.messageKey) {
    return { key: appError.messageKey, text: null };
  }
  if (appError.problem.status === 0) {
    return { key: 'error.network', text: null };
  }
  const detail = appError.problem.detail ?? appError.problem.title;
  return detail
    ? { key: null, text: detail }
    : { key: 'error.generic', text: null };
}
