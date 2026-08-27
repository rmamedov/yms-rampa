import {
  addDaysToDateKey,
  dateChipKind,
  dateChipLabel,
  formatKyivDateTime,
  formatKyivTime,
  kyivDateKey,
} from './time.util';

describe('форматування часу в Europe/Kyiv (DRV-05)', () => {
  it('момент показується у київському часі', () => {
    // Літній час у Києві: UTC+3 → 09:00Z = 12:00.
    expect(formatKyivTime('2026-08-27T09:00:00Z')).toBe('12:00');
  });

  it('дата й час — DD.MM.YYYY HH:MM', () => {
    expect(formatKyivDateTime('2026-08-27T09:00:00Z')).toBe('27.08.2026 12:00');
  });

  it('kyivDateKey віддає календарну дату Києва, а не UTC', () => {
    // 21:30 UTC = 00:30 наступного дня у Києві (літній час).
    expect(kyivDateKey(Date.parse('2026-08-27T21:30:00Z'))).toBe('2026-08-28');
  });

  it('addDaysToDateKey переходить через межу місяця', () => {
    expect(addDaysToDateKey('2026-08-31', 1)).toBe('2026-09-01');
    expect(addDaysToDateKey('2026-09-01', -1)).toBe('2026-08-31');
  });
});

describe('чипи дат (DRV-13)', () => {
  const now = Date.parse('2026-08-27T09:00:00Z');
  const t = (key: string) =>
    key === 'sheet.today' ? 'Сьогодні' : key === 'sheet.tomorrow' ? 'Завтра' : key;

  it('розрізняє сьогодні, завтра та інші дати', () => {
    expect(dateChipKind('2026-08-27', now)).toBe('today');
    expect(dateChipKind('2026-08-28', now)).toBe('tomorrow');
    expect(dateChipKind('2026-08-29', now)).toBe('other');
  });

  it('мітка інших дат — DD.MM', () => {
    expect(dateChipLabel('2026-08-27', now, t)).toBe('Сьогодні');
    expect(dateChipLabel('2026-08-28', now, t)).toBe('Завтра');
    expect(dateChipLabel('2026-08-29', now, t)).toBe('29.08');
  });
});
