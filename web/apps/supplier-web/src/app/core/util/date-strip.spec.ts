import { buildDateStrip, clampOffset } from './date-strip';

const today = '2026-03-12';

describe('стрічка дат філії (SUP-SLOT-01, GRID-03)', () => {
  it('за замовчуванням показує 7 днів уперед, включно з сьогодні', () => {
    const strip = buildDateStrip(today, 0, 7, 14);
    expect(strip.dates).toHaveLength(7);
    expect(strip.dates[0]).toBe('2026-03-12');
    expect(strip.dates[6]).toBe('2026-03-18');
    expect(strip.canPrev).toBe(false);
    expect(strip.canNext).toBe(true);
  });

  it('не дозволяє вийти за межі горизонту бронювання', () => {
    const strip = buildDateStrip(today, 14, 7, 14);
    expect(strip.canNext).toBe(false);
    expect(strip.dates.at(-1)).toBe('2026-03-26');
    expect(strip.dates.every((date) => date <= '2026-03-26')).toBe(true);
  });

  it('обрізає надто великий і відʼємний зсув', () => {
    expect(clampOffset(-5, 7, 14)).toBe(0);
    expect(clampOffset(99, 7, 14)).toBe(8);
    expect(clampOffset(3, 7, 14)).toBe(3);
  });

  it('для короткого горизонту показує лише доступні дати', () => {
    const strip = buildDateStrip(today, 0, 7, 3);
    expect(strip.dates).toEqual([
      '2026-03-12',
      '2026-03-13',
      '2026-03-14',
      '2026-03-15',
    ]);
    expect(strip.canNext).toBe(false);
  });
});
