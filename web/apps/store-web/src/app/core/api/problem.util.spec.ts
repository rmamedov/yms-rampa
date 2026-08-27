import { HttpErrorResponse } from '@angular/common/http';
import { describeError, messageKeyForCode, toAppError } from './problem.util';
import { AppError } from '../models/problem.model';

function problemResponse(status: number, body: unknown): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    statusText: 'Error',
    error: body,
    url: '/api/store/v1/bookings/bk-1/complete',
  });
}

describe('Обробка помилок RFC 7807', () => {
  it('мапить відомі коди на ключі i18n', () => {
    expect(messageKeyForCode('SLOT_ALREADY_BOOKED')).toBe(
      'error.SLOT_ALREADY_BOOKED',
    );
    expect(messageKeyForCode('VEHICLE_TOO_HEAVY')).toBe(
      'error.VEHICLE_TOO_HEAVY',
    );
    expect(messageKeyForCode('DATE_OUT_OF_HORIZON')).toBe(
      'error.DATE_OUT_OF_HORIZON',
    );
    expect(messageKeyForCode('BOOKING_LIMIT_EXCEEDED')).toBe(
      'error.BOOKING_LIMIT_EXCEEDED',
    );
    expect(messageKeyForCode('SLOT_HELD')).toBe('error.SLOT_HELD');
    expect(messageKeyForCode('SOMETHING_NEW')).toBeNull();
    expect(messageKeyForCode(undefined)).toBeNull();
  });

  it('розбирає problem+json у AppError зі статусом і кодом', () => {
    const error = toAppError(
      problemResponse(409, {
        type: 'https://yms.rampa/problems/conflict',
        title: 'Conflict',
        status: 409,
        code: 'BOOKING_STATUS_CONFLICT',
        detail: 'Статус уже змінено',
      }),
    );
    expect(error).toBeInstanceOf(AppError);
    expect(error.status).toBe(409);
    expect(error.code).toBe('BOOKING_STATUS_CONFLICT');
    expect(error.messageKey).toBe('error.BOOKING_STATUS_CONFLICT');
  });

  it('для невідомого коду показує detail з бекенду', () => {
    const described = describeError(
      problemResponse(422, {
        status: 422,
        code: 'SOME_NEW_RULE',
        detail: 'Порушено нове правило',
      }),
    );
    expect(described).toEqual({ key: null, text: 'Порушено нове правило' });
  });

  it('мережеву помилку показує окремим повідомленням', () => {
    expect(describeError(problemResponse(0, null))).toEqual({
      key: 'error.network',
      text: null,
    });
  });

  it('усі відомі коди сценаріїв розділу 9.11 мають повідомлення', () => {
    const codes = [
      'BOOKING_STATUS_CONFLICT',
      'STORE_FORBIDDEN',
      'NO_SHOW_TOO_EARLY',
      'ETA_BEFORE_SLOT_START',
      'REJECT_REASON_REQUIRED',
      'SLOT_ALREADY_BOOKED',
      'VEHICLE_TOO_HEAVY',
      'RAMP_SLOT_TAKEN',
      'SLOT_HAS_ACTIVE_BOOKING',
    ];
    for (const code of codes) {
      expect(messageKeyForCode(code)).toBe(`error.${code}`);
    }
  });
});
