import {
  addDaysToDateKey,
  diffDateKeys,
  formatTime,
  isoDayOfWeek,
  kyivMinutesOfDay,
  kyivToUtcIso,
  minutesBetween,
  parseHhMm,
  startOfKyivWeek,
  toHhMm,
  toKyivDateKey,
} from './date.util';

describe('Час у Europe/Kyiv', () => {
  it('форматує UTC-мітку в київський час', () => {
    // Літній час: UTC+3.
    expect(formatTime('2026-08-27T07:00:00.000Z')).toBe('10:00');
    // Зимовий час: UTC+2.
    expect(formatTime('2026-01-15T07:00:00.000Z')).toBe('09:00');
  });

  it('визначає київську календарну дату для межі доби', () => {
    expect(toKyivDateKey('2026-08-27T21:30:00.000Z')).toBe('2026-08-28');
    expect(toKyivDateKey('2026-08-27T20:59:00.000Z')).toBe('2026-08-27');
  });

  it('конвертує київський локальний час у UTC ISO в обидва боки', () => {
    const iso = kyivToUtcIso('2026-08-27', parseHhMm('10:00'));
    expect(iso).toBe('2026-08-27T07:00:00.000Z');
    expect(kyivMinutesOfDay(iso)).toBe(600);
    expect(toKyivDateKey(iso)).toBe('2026-08-27');

    const winter = kyivToUtcIso('2026-01-15', parseHhMm('09:00'));
    expect(winter).toBe('2026-01-15T07:00:00.000Z');
  });

  it('рахує зсуви дат і день тижня', () => {
    expect(addDaysToDateKey('2026-08-31', 1)).toBe('2026-09-01');
    expect(addDaysToDateKey('2026-03-01', -1)).toBe('2026-02-28');
    expect(diffDateKeys('2026-08-27', '2026-08-30')).toBe(3);
    expect(isoDayOfWeek('2026-08-27')).toBe(4); // четвер
    expect(startOfKyivWeek('2026-08-27')).toBe('2026-08-24'); // понеділок
    expect(startOfKyivWeek('2026-08-30')).toBe('2026-08-24'); // неділя → той самий тиждень
  });

  it('рахує різницю у хвилинах і форматує HH:MM', () => {
    expect(
      minutesBetween('2026-08-27T07:00:00.000Z', '2026-08-27T07:45:00.000Z'),
    ).toBe(45);
    expect(minutesBetween(null, '2026-08-27T07:45:00.000Z')).toBeNull();
    expect(toHhMm(605)).toBe('10:05');
    expect(parseHhMm('08:30')).toBe(510);
  });
});
