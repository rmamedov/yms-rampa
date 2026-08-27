import {
  ApiProblemError,
  ERROR_CODES,
  isApiProblem,
  problem,
  problemError,
  toProblem,
} from './problem';

describe('RFC 7807 problem+json', () => {
  it('збирає розширення бекенду з верхнього рівня документа в meta', () => {
    // Саме так їх складає ProblemResponseFactory: поруч зі стандартними
    // членами, а не у вкладеному об'єкті.
    const body = {
      type: 'about:blank',
      title: 'Не пройдено валідацію',
      status: 422,
      detail: 'Ця філія приймає авто до 10 т',
      code: ERROR_CODES.vehicleTooHeavy,
      requestId: 'req-1',
      maxVehicleWeightTons: 10,
      actualWeightTons: 20,
    };
    const result = toProblem({ status: 422, error: body });
    expect(isApiProblem(body)).toBe(true);
    expect(result.code).toBe('VEHICLE_TOO_HEAVY');
    expect(result.detail).toBe('Ця філія приймає авто до 10 т');
    expect(result.requestId).toBe('req-1');
    expect(result.meta).toEqual({
      maxVehicleWeightTons: 10,
      actualWeightTons: 20,
    });
  });

  it('лишає meta порожньою, коли розширень немає', () => {
    const result = toProblem({
      status: 409,
      error: {
        type: 'about:blank',
        title: 'Конфлікт',
        status: 409,
        detail: 'Слот зайнято',
        code: ERROR_CODES.slotHeld,
        requestId: 'req-2',
      },
    });
    expect(result.meta).toBeUndefined();
  });

  it('перетворює мережеву помилку (status 0) на NETWORK_ERROR', () => {
    const result = toProblem({ status: 0, error: new ProgressEvent('error') });
    expect(result.code).toBe(ERROR_CODES.network);
    expect(result.status).toBe(0);
  });

  it('мапить статуси без тіла на канонічні коди', () => {
    expect(toProblem({ status: 401 }).code).toBe(ERROR_CODES.authTokenInvalid);
    expect(toProblem({ status: 403 }).code).toBe(ERROR_CODES.accessDenied);
    expect(toProblem({ status: 404 }).code).toBe(ERROR_CODES.notFound);
    expect(toProblem({ status: 503 }).code).toBe(ERROR_CODES.internalError);
    expect(toProblem({ status: 418 }).code).toBe(ERROR_CODES.unknown);
  });

  it('пропускає ApiProblemError без змін', () => {
    const error = problemError(409, ERROR_CODES.slotAlreadyBooked, 'зайнято');
    expect(error).toBeInstanceOf(ApiProblemError);
    expect(toProblem(error)).toEqual(
      problem(409, ERROR_CODES.slotAlreadyBooked, 'зайнято'),
    );
  });
});
