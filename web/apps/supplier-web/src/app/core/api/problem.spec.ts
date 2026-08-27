import {
  ApiProblemError,
  ERROR_CODES,
  isApiProblem,
  problem,
  problemError,
  toProblem,
} from './problem';

describe('RFC 7807 problem+json', () => {
  it('розпізнає problem-документ бекенду в тілі помилки', () => {
    const body = {
      type: 'https://yms.rampa/errors/vehicle_too_heavy',
      title: 'VEHICLE_TOO_HEAVY',
      status: 422,
      detail: 'Ця філія приймає авто до 10 т',
      code: ERROR_CODES.vehicleTooHeavy,
      meta: { tons: 10 },
    };
    const result = toProblem({ status: 422, error: body });
    expect(isApiProblem(body)).toBe(true);
    expect(result.code).toBe('VEHICLE_TOO_HEAVY');
    expect(result.detail).toBe('Ця філія приймає авто до 10 т');
    expect(result.meta).toEqual({ tons: 10 });
  });

  it('перетворює мережеву помилку (status 0) на NETWORK_ERROR', () => {
    const result = toProblem({ status: 0, error: new ProgressEvent('error') });
    expect(result.code).toBe(ERROR_CODES.network);
    expect(result.status).toBe(0);
  });

  it('мапить 401 на UNAUTHORIZED, 5xx — на NETWORK_ERROR', () => {
    expect(toProblem({ status: 401 }).code).toBe(ERROR_CODES.unauthorized);
    expect(toProblem({ status: 503 }).code).toBe(ERROR_CODES.network);
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
