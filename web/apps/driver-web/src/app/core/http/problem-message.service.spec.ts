import { TestBed } from '@angular/core/testing';
import { ProblemMessageService } from './problem-message.service';
import { ApiProblemError, toProblem } from '../models/problem.model';

describe('ProblemMessageService (API-02, RFC 7807)', () => {
  let service: ProblemMessageService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(ProblemMessageService);
  });

  it('відомі коди мають власні українські сценарні тексти', () => {
    expect(
      service.messageFor(new ApiProblemError(401, { code: 'AUTH_INVALID_CREDENTIALS' })),
    ).toBe('Невірний телефон або пароль');
    expect(
      service.messageFor(new ApiProblemError(403, { code: 'AUTH_ACCOUNT_DISABLED' })),
    ).toBe('Ваш обліковий запис вимкнено. Зверніться до постачальника');
    expect(
      service.messageFor(new ApiProblemError(409, { code: 'SLOT_ALREADY_BOOKED' })),
    ).toBe('Цей слот уже заброньовано');
    expect(
      service.messageFor(new ApiProblemError(422, { code: 'VEHICLE_TOO_HEAVY' })),
    ).toBe('Тоннаж авто перевищує обмеження рампи');
    expect(
      service.messageFor(new ApiProblemError(422, { code: 'DATE_OUT_OF_HORIZON' })),
    ).toBe('Дата поза горизонтом бронювання');
    expect(
      service.messageFor(new ApiProblemError(409, { code: 'BOOKING_LIMIT_EXCEEDED' })),
    ).toBe('Перевищено ліміт бронювань');
    expect(service.messageFor(new ApiProblemError(409, { code: 'SLOT_HELD' }))).toBe(
      'Слот тимчасово утримується іншим користувачем',
    );
  });

  it('код у snake_case з бекенду нормалізується', () => {
    const error = new ApiProblemError(409, { code: 'slot_already_booked' });
    expect(error.code).toBe('SLOT_ALREADY_BOOKED');
    expect(error.is('SLOT_ALREADY_BOOKED')).toBe(true);
    expect(service.codeOf(error)).toBe('SLOT_ALREADY_BOOKED');
  });

  it('невідомий код показує detail із бекенду', () => {
    expect(
      service.messageFor(
        new ApiProblemError(409, { code: 'SOME_NEW_CODE', detail: 'Щось пішло не так' }),
      ),
    ).toBe('Щось пішло не так');
  });

  it('без detail показує повідомлення першої violation', () => {
    expect(
      service.messageFor(
        new ApiProblemError(422, {
          code: 'VALIDATION_ERROR',
          violations: [{ field: 'orderId', code: 'length', message: 'Від 1 до 64' }],
        }),
      ),
    ).toBe('Від 1 до 64');
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
    const parsed = toProblem(409, {
      type: 'https://yms/errors/slot',
      title: 'Конфлікт',
      status: 409,
      detail: 'Слот зайнято',
      code: 'SLOT_ALREADY_BOOKED',
      requestId: 'req-1',
    });
    expect(parsed.code).toBe('SLOT_ALREADY_BOOKED');
    expect(parsed.detail).toBe('Слот зайнято');

    expect(toProblem(500, 'plain text')).toEqual({ status: 500 });
  });
});
