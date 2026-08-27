import { TestBed } from '@angular/core/testing';
import { ToastService } from './toast.service';
import { ERROR_CODES, problem, problemError } from '../../core/api/problem';

describe('ToastService — повідомлення про помилки (SUP-ERR-01..03)', () => {
  let toasts: ToastService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    toasts = TestBed.inject(ToastService);
  });

  it('перекладає відомі коди помилок з підстановкою деталей', () => {
    expect(
      toasts.messageFor(
        problem(409, ERROR_CODES.slotAlreadyBooked, 'raw detail'),
      ),
    ).toBe('Цей слот щойно забронював інший постачальник');

    // Плейсхолдери словника — імена розширень problem-документа бекенду.
    expect(
      toasts.messageFor(
        problem(422, ERROR_CODES.vehicleTooHeavy, 'raw', {
          maxVehicleWeightTons: 10,
          actualWeightTons: 20,
        }),
      ),
    ).toBe('Ця філія приймає авто до 10 т');

    expect(
      toasts.messageFor(
        problem(422, ERROR_CODES.dateOutOfHorizon, 'raw', { horizonDays: 14 }),
      ),
    ).toContain('14');

    expect(
      toasts.messageFor(
        problem(422, ERROR_CODES.bookingLimitExceeded, 'raw', { limit: 50 }),
      ),
    ).toContain('50');
  });

  it('показує detail з бекенду для незнайомого коду', () => {
    expect(
      toasts.messageFor(problem(400, 'SOME_NEW_CODE', 'Особлива помилка')),
    ).toBe('Особлива помилка');
  });

  it('додає тост і дозволяє його закрити', () => {
    const returned = toasts.problem(
      problemError(409, ERROR_CODES.slotHeld, 'зайнято'),
    );
    expect(returned.code).toBe(ERROR_CODES.slotHeld);
    expect(toasts.toasts()).toHaveLength(1);
    expect(toasts.toasts()[0].kind).toBe('error');

    toasts.dismiss(toasts.toasts()[0].id);
    expect(toasts.toasts()).toHaveLength(0);
  });
});
