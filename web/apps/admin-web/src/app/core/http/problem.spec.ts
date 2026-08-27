import { HttpErrorResponse } from '@angular/common/http';
import {
  ApiError,
  isKnownErrorCode,
  parseProblem,
  problemMessageKeyOrText,
} from './problem';

describe('RFC 7807 — розбір application/problem+json', () => {
  it('розбирає тіло problem+json з code і detail', () => {
    const error = parseProblem(
      new HttpErrorResponse({
        status: 422,
        error: {
          type: 'about:blank',
          title: 'Перевищено ліміт',
          status: 422,
          detail: 'Маса авто перевищує ліміт магазину',
          code: 'VEHICLE_TOO_HEAVY',
          requestId: 'req-1',
        },
      }),
    );
    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(422);
    expect(error.code).toBe('VEHICLE_TOO_HEAVY');
    expect(error.problem.requestId).toBe('req-1');
  });

  it('відомі коди мапляться на ключі словника', () => {
    for (const code of [
      'SLOT_ALREADY_BOOKED',
      'SLOT_HELD',
      'VEHICLE_TOO_HEAVY',
      'DATE_OUT_OF_HORIZON',
      'BOOKING_LIMIT_EXCEEDED',
    ]) {
      expect(isKnownErrorCode(code)).toBe(true);
      const error = new ApiError(422, { code, detail: 'raw detail' });
      expect(problemMessageKeyOrText(error)).toEqual({ key: `error.${code}` });
    }
  });

  it('невідомий код показує detail з бекенду (UI-04)', () => {
    const error = new ApiError(422, {
      code: 'SOMETHING_NEW',
      detail: 'Дію не виконано з невідомої причини',
    });
    expect(problemMessageKeyOrText(error)).toEqual({
      text: 'Дію не виконано з невідомої причини',
    });
  });

  it('обрив мережі (status 0) — окреме повідомлення', () => {
    const error = parseProblem(
      new HttpErrorResponse({ status: 0, error: new ProgressEvent('error') }),
    );
    expect(error.status).toBe(0);
    expect(problemMessageKeyOrText(error)).toEqual({ key: 'error.network' });
  });

  it('невідомий обʼєкт помилки не ламає розбір', () => {
    const error = parseProblem(new Error('boom'));
    expect(error).toBeInstanceOf(ApiError);
    expect(problemMessageKeyOrText(error)).toEqual({ key: 'error.network' });
  });

  it('вже розібрана ApiError повертається без змін', () => {
    const original = new ApiError(403, { code: 'RBAC_SCOPE_VIOLATION' });
    expect(parseProblem(original)).toBe(original);
  });
});
