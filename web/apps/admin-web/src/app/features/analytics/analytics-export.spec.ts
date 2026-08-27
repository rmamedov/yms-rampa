import { addDays } from '../../core/utils/time.util';
import { ANALYTICS_PRESETS, presetRange } from './analytics-export';

describe('ANL-10 — пресети періоду', () => {
  const today = '2026-08-27';

  it('«сьогодні» — одна доба', () => {
    expect(presetRange('today', today, addDays)).toEqual({
      from: today,
      to: today,
    });
  });

  it('«7 днів» — сьогодні і шість попередніх', () => {
    expect(presetRange('7d', today, addDays)).toEqual({
      from: '2026-08-21',
      to: today,
    });
  });

  it('«30 днів» — сьогодні і 29 попередніх', () => {
    expect(presetRange('30d', today, addDays)).toEqual({
      from: '2026-07-29',
      to: today,
    });
  });

  it('перелік пресетів збігається з допустимими значеннями бекенду', () => {
    expect([...ANALYTICS_PRESETS]).toEqual(['today', '7d', '30d']);
  });
});
