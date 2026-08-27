import { TestBed } from '@angular/core/testing';
import { ProblemMessageService } from './problem-message.service';
import { ApiProblemError, toProblem } from '../models/problem.model';

describe('ProblemMessageService (API-02, RFC 7807)', () => {
  let service: ProblemMessageService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(ProblemMessageService);
  });

  it('коди контуру водія мають власні українські сценарні тексти', () => {
    expect(
      service.messageFor(new ApiProblemError(401, { code: 'AUTH_INVALID_CREDENTIALS' })),
    ).toBe('Невірний телефон або пароль');
    expect(
      service.messageFor(new ApiProblemError(403, { code: 'AUTH_ACCOUNT_DISABLED' })),
    ).toBe('Ваш обліковий запис вимкнено. Зверніться до постачальника');
    expect(
      service.messageFor(new ApiProblemError(422, { code: 'PARTNER_LOGIN_INVALID' })),
    ).toBe('Невірний формат телефону');
    expect(
      service.messageFor(new ApiProblemError(401, { code: 'AUTH_TOKEN_EXPIRED' })),
    ).toBe('Сесія завершилась. Увійдіть повторно.');
    expect(
      service.messageFor(new ApiProblemError(403, { code: 'ACCESS_DENIED' })),
    ).toBe('Доступ до цих даних закрито');
    expect(
      service.messageFor(new ApiProblemError(404, { code: 'ROUTE_NOT_FOUND' })),
    ).toBe('Дані не знайдено');
  });

  it('код у snake_case з бекенду нормалізується', () => {
    const error = new ApiProblemError(403, { code: 'access_denied' });
    expect(error.code).toBe('ACCESS_DENIED');
    expect(error.is('ACCESS_DENIED')).toBe(true);
    expect(service.codeOf(error)).toBe('ACCESS_DENIED');
  });

  it('невідомий код показує detail із бекенду', () => {
    expect(
      service.messageFor(
        new ApiProblemError(409, { code: 'SOME_NEW_CODE', detail: 'Щось пішло не так' }),
      ),
    ).toBe('Щось пішло не так');
  });

  it('422 VALIDATION_FAILED без detail показує першу violation', () => {
    expect(
      service.messageFor(
        new ApiProblemError(422, {
          code: 'VALIDATION_FAILED',
          violations: [
            { field: 'date', code: 'format', message: 'Формат YYYY-MM-DD' },
          ],
        }),
      ),
    ).toBe('Формат YYYY-MM-DD');
  });

  it('довільна помилка отримує загальний текст', () => {
    expect(service.messageFor(new Error('boom'), 'sheet.loadError')).toBe(
      'Не вдалося завантажити маршрутний лист',
    );
  });

  it('розпізнає мережеву недоступність', () => {
    expect(service.isNetworkError(new ApiProblemError(0, {}))).toBe(true);
    expect(service.isNetworkError(new ApiProblemError(409, {}))).toBe(false);
  });

  it('toProblem розбирає тіло problem+json і ігнорує сміття', () => {
    const parsed = toProblem(422, {
      type: 'about:blank',
      title: 'Не пройдено валідацію',
      status: 422,
      detail: 'Параметр «date» має бути у форматі YYYY-MM-DD',
      code: 'VALIDATION_FAILED',
      requestId: 'req-1',
    });
    expect(parsed.code).toBe('VALIDATION_FAILED');
    expect(parsed.detail).toBe('Параметр «date» має бути у форматі YYYY-MM-DD');

    expect(toProblem(500, 'plain text')).toEqual({ status: 500 });
  });
});
