import { NO_DELAY, arrivalAvailable, type RoutePoint } from './route-sheet.model';
import { kyivDateKey } from '../util/time.util';

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

  it('на завтрашню точку відмітити прибуття не можна', () => {
    const now = Date.parse('2026-08-27T12:00:00Z');

    expect(arrivalAvailable(point(slot), now)).toBe(false);
  });

  /**
   * Маршрутний лист водій відкриває вранці, а точки в ньому — на весь день:
   * о 03:10 точка о 13:00 має лишатися доступною, інакше застосунок сам собі
   * забороняє те, що бекенд приймає.
   */
  it('у день візиту відмітка доступна навіть задовго до слоту', () => {
    const now = Date.parse('2026-08-28T00:10:00Z'); // 03:10 за Києвом

    expect(arrivalAvailable(point(slot), now)).toBe(true);
  });

  it('після кінця слоту відмітка лишається доступною — це запізнення, не заборона', () => {
    const now = Date.parse('2026-08-28T18:00:00Z');

    expect(arrivalAvailable(point(slot), now)).toBe(true);
  });

  /** Межа рахується за календарем Києва, а не за UTC. */
  it('київська північ, а не UTC, відкриває вікно', () => {
    // 2026-08-27 22:00Z = вже 01:00 28 серпня в Києві.
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-27T22:00:00Z'))).toBe(
      true,
    );
    // 2026-08-27 20:59Z = 23:59 27 серпня в Києві — ще зарано.
    expect(arrivalAvailable(point(slot), Date.parse('2026-08-27T20:59:00Z'))).toBe(
      false,
    );
  });

  it('за замовчуванням порівнюється з поточним моментом', () => {
    const todaySlot = `${kyivDateKey()}T00:00:00Z`;

    expect(arrivalAvailable(point(todaySlot))).toBe(true);
  });
});
