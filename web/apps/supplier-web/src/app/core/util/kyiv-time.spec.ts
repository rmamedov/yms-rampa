import {
  addDays,
  diffDays,
  formatCountdown,
  kyivDateIso,
  kyivDayLabel,
  kyivOffsetMinutes,
  kyivTimeHm,
  kyivToUtc,
} from './kyiv-time';

describe('час Europe/Kyiv (SUP-UX-03)', () => {
  it('враховує перехід на літній час при конвертації в UTC', () => {
    // До переходу (EET, UTC+2)
    expect(kyivToUtc('2026-03-28', '10:00').toISOString()).toBe(
      '2026-03-28T08:00:00.000Z',
    );
    // Після переходу (EEST, UTC+3)
    expect(kyivToUtc('2026-03-30', '10:00').toISOString()).toBe(
      '2026-03-30T07:00:00.000Z',
    );
  });

  it('обчислює зсув зони для зимового і літнього періодів', () => {
    expect(kyivOffsetMinutes(new Date('2026-01-15T12:00:00Z'))).toBe(120);
    expect(kyivOffsetMinutes(new Date('2026-07-15T12:00:00Z'))).toBe(180);
  });

  it('визначає київську дату і час для UTC-моменту біля півночі', () => {
    const instant = new Date('2026-06-01T21:30:00Z');
    expect(kyivDateIso(instant)).toBe('2026-06-02');
    expect(kyivTimeHm(instant)).toBe('00:30');
  });

  it('додає дні календарно і рахує різницю', () => {
    expect(addDays('2026-03-28', 3)).toBe('2026-03-31');
    expect(addDays('2026-12-30', 3)).toBe('2027-01-02');
    expect(diffDays('2026-03-28', '2026-04-04')).toBe(7);
    expect(diffDays('2026-03-28', '2026-03-27')).toBe(-1);
  });

  it('формує підпис дати українською і таймер mm:ss', () => {
    expect(kyivDayLabel('2026-03-12')).toBe('12 березня');
    expect(formatCountdown(300)).toBe('05:00');
    expect(formatCountdown(59)).toBe('00:59');
    expect(formatCountdown(-5)).toBe('00:00');
  });
});
