import {
  addDaysToDateKey,
  arriveWindowState,
  dateChipKind,
  dateChipLabel,
  formatSlotRange,
  kyivDateKey,
  kyivLocalInputToIso,
  toKyivLocalInputValue,
} from './time.util';

describe('arriveWindowState (DRV-24)', () => {
  const start = Date.parse('2026-08-27T09:00:00Z');
  const end = Date.parse('2026-08-27T10:00:00Z');
  const startIso = new Date(start).toISOString();
  const endIso = new Date(end).toISOString();

  it('за 61 хв до слоту кнопка неактивна', () => {
    expect(arriveWindowState(startIso, endIso, start - 61 * 60_000)).toBe(
      'too_early',
    );
  });

  it('рівно за 60 хв до слоту вікно відкрите', () => {
    expect(arriveWindowState(startIso, endIso, start - 60 * 60_000)).toBe('open');
  });

  it('усередині слоту вікно відкрите', () => {
    expect(arriveWindowState(startIso, endIso, start + 30 * 60_000)).toBe('open');
  });

  it('на межі кінця слоту ще open, після — late', () => {
    expect(arriveWindowState(startIso, endIso, end)).toBe('open');
    expect(arriveWindowState(startIso, endIso, end + 1)).toBe('late');
  });
});

describe('форматування часу в Europe/Kyiv (DRV-05, DRV-15)', () => {
  it('слот показується як HH:MM–HH:MM у київському часі', () => {
    // Літній час у Києві: UTC+3 → 09:00Z = 12:00.
    expect(
      formatSlotRange('2026-08-27T09:00:00Z', '2026-08-27T10:30:00Z'),
    ).toBe('12:00–13:30');
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

describe('перетворення київського локального часу в UTC ISO', () => {
  it('туди-назад дає той самий момент', () => {
    const at = Date.parse('2026-08-27T09:15:00Z');
    const local = toKyivLocalInputValue(at);
    expect(local).toBe('2026-08-27T12:15');
    const iso = kyivLocalInputToIso(local);
    expect(iso).not.toBeNull();
    expect(Date.parse(iso as string)).toBe(at);
  });

  it('невалідний рядок → null', () => {
    expect(kyivLocalInputToIso('не дата')).toBeNull();
  });
});
