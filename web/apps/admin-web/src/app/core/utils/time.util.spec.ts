import {
  addDays,
  dayOfWeek,
  diffDays,
  formatDate,
  formatDateTime,
  formatDuration,
  isOnFiveMinuteStep,
  isValidDate,
  isValidTime,
  minutesToTime,
  timeToMinutes,
} from './time.util';

describe('ADM-03 — час у Europe/Kyiv в UI, UTC у моделях', () => {
  it('парсить і форматує HH:MM', () => {
    expect(isValidTime('08:00')).toBe(true);
    expect(isValidTime('24:00')).toBe(false);
    expect(isValidTime('8:00')).toBe(false);
    expect(timeToMinutes('08:30')).toBe(510);
    expect(timeToMinutes('99:99')).toBe(-1);
    expect(minutesToTime(510)).toBe('08:30');
    expect(minutesToTime(0)).toBe('00:00');
  });

  it('перевіряє крок 5 хвилин', () => {
    expect(isOnFiveMinuteStep('08:05')).toBe(true);
    expect(isOnFiveMinuteStep('08:07')).toBe(false);
  });

  it('перевіряє коректність дати', () => {
    expect(isValidDate('2026-08-27')).toBe(true);
    expect(isValidDate('2026-02-30')).toBe(false);
    expect(isValidDate('27.08.2026')).toBe(false);
  });

  it('арифметика дат працює через межу місяця', () => {
    expect(addDays('2026-08-31', 1)).toBe('2026-09-01');
    expect(addDays('2026-01-01', -1)).toBe('2025-12-31');
    expect(diffDays('2026-08-27', '2026-09-03')).toBe(7);
    expect(diffDays('2026-09-03', '2026-08-27')).toBe(-7);
  });

  it('ISO-день тижня: 1 = понеділок, 7 = неділя', () => {
    expect(dayOfWeek('2026-09-07')).toBe(1);
    expect(dayOfWeek('2026-09-13')).toBe(7);
  });

  it('форматує дату у вигляді дд.мм.рррр', () => {
    expect(formatDate('2026-08-27')).toBe('27.08.2026');
    expect(formatDate(null)).toBe('—');
    expect(formatDate('bad')).toBe('—');
  });

  it('форматує UTC-мітку в київський час', () => {
    // 27.08.2026 — літній час у Києві (UTC+3)
    expect(formatDateTime('2026-08-27T09:00:00.000Z')).toContain('12:00');
    expect(formatDateTime('2026-08-27T09:00:00.000Z')).toContain('27.08.2026');
    expect(formatDateTime(null)).toBe('—');
  });

  it('форматує тривалість синхронізації', () => {
    expect(formatDuration(450)).toBe('450 мс');
    expect(formatDuration(42_000)).toBe('42 с');
    expect(formatDuration(125_000)).toBe('2 хв 5 с');
  });
});
