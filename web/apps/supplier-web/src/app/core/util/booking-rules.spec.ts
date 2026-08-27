import {
  canCancel,
  canChangeDriverOrVehicle,
  canTransfer,
  isLocked,
} from './booking-rules';
import type { BookingStatus } from '../models/models';

describe('правила редагування точок маршрутного листа (SUP-RS-06, SUP-RS-07)', () => {
  it('дозволяє перенесення і скасування лише для статусу booked', () => {
    expect(canTransfer('booked')).toBe(true);
    expect(canCancel('booked')).toBe(true);
    for (const status of [
      'arrived',
      'unloading',
      'completed',
      'no_show',
      'cancelled',
    ] as BookingStatus[]) {
      expect(canTransfer(status)).toBe(false);
      expect(canCancel(status)).toBe(false);
    }
  });

  it('блокує будь-які дії для термінальних і виконуваних статусів', () => {
    expect(isLocked('booked')).toBe(false);
    expect(isLocked('arrived')).toBe(true);
    expect(isLocked('completed')).toBe(true);
    expect(isLocked('rejected')).toBe(true);
  });

  it('дозволяє зміну водія до прибуття на місце', () => {
    expect(canChangeDriverOrVehicle('booked')).toBe(true);
    expect(canChangeDriverOrVehicle('arrived')).toBe(false);
  });
});
