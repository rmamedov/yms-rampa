import {
  ARRIVAL_WINDOW_MINUTES,
  NO_DELAY,
  arrivalAvailable,
  arrivalOpensAt,
  type RoutePoint,
} from './route-sheet.model';

function point(slotStart: string): RoutePoint {
  return {
    bookingId: 'bk-1',
    city: 'Київ',
    storeName: 'Сільпо №1998',
    address: 'вул. Берковецька, 6Д',
    latitude: 50.4869,
    longitude: 30.3897,
    localTime: '13:00',
    slotStart,
    rampId: 'ramp-2',
    rampNumber: 2,
    rampName: 'Рампа 2',
    orderId: null,
    palletsCount: 8,
    plateNumber: 'AA4721OB',
    driverId: 'drv-1',
    status: 'booked',
    delayed: NO_DELAY,
    arrivedAt: null,
  };
}

/**
 * D-04, ISSUE-13: доступність відмітки «На місці» за часом.
 *
 * Дзеркало ArrivalWindow у booking-service: рішення однакове на обох боках,
 * інакше водій тиснув би кнопку, яку сервер відхиляє з 422.
 */
describe('доступність відмітки «На місці» (ArrivalWindow)', () => {
  // 2026-08-28 13:00 за Києвом = 10:00Z (літній час, UTC+3).
  const slot = '2026-08-28T10:00:00Z';

  it('ширина вікна збігається з доменною константою', () => {
    expect(ARRIVAL_WINDOW_MINUTES).toBe(60);
    expect(arrivalOpensAt(point(slot))).toBe(Date.parse('2026-08-28T09:00:00Z'));
  });

  it('за годину до слоту вікно вже відкрите', () => {
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-28T09:00:00Z'))).toBe(
      true,
    );
  });

  it('за хвилину до відкриття — ще ні', () => {
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-28T08:59:00Z'))).toBe(
      false,
    );
  });

  /**
   * Рівно та шкода, заради якої правило й писалося: вранці відмітити
   * прибуття на вечірню точку не можна — інакше магазин цілий день бачить
   * у черзі машину, якої немає.
   */
  it('вранці на вечірню точку відмітити прибуття не можна', () => {
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-28T03:00:00Z'))).toBe(
      false,
    );
  });

  it('на завтрашню точку — тим паче', () => {
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-27T12:00:00Z'))).toBe(
      false,
    );
  });

  it('після кінця слоту відмітка лишається доступною — це запізнення, не заборона', () => {
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-28T18:00:00Z'))).toBe(
      true,
    );
  });

  it('за замовчуванням порівнюється з поточним моментом', () => {
    const passed = new Date(Date.now() - 60 * 60_000).toISOString();

    expect(arrivalAvailable(point(passed))).toBe(true);
  });
});
