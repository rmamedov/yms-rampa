import { AnalyticsDashboard, AnalyticsFilter } from '../../core/models';
import { buildCsv, escapeCsvCell, UTF8_BOM } from '../../core/utils/csv.util';
import { addDays } from '../../core/utils/time.util';
import { buildWidgetCsv, filterHeader, presetRange } from './analytics-export';

const filter: AnalyticsFilter = {
  from: '2026-08-01',
  to: '2026-08-27',
  cities: ['Київ'],
  storeIds: [],
  supplierIds: ['sup-1'],
};

const dashboard: AnalyticsDashboard = {
  recalculatedAt: '2026-08-27T10:00:00.000Z',
  utilization: [
    {
      storeId: 'st-1998',
      storeName: 'Сільпо 1998, просп. Володимира Івасюка, 46',
      city: 'Київ',
      bookedSlotMinutes: 300,
      availableSlotMinutes: 600,
      utilization: 0.5,
    },
  ],
  deliveries: [
    {
      supplierId: 'sup-1',
      supplierName: 'ТОВ «Молочний Дім»',
      booked: 4,
      completed: 9,
      cancelled: 1,
      noShow: 2,
    },
  ],
  noShow: [
    {
      supplierId: 'sup-1',
      supplierName: 'ТОВ «Молочний Дім»',
      storeName: 'Сільпо 1998',
      noShow: 2,
      total: 16,
      share: 0.125,
    },
  ],
  unloading: [
    {
      storeId: 'st-1998',
      storeName: 'Сільпо 1998',
      avgMinutes: 26,
      medianMinutes: 24,
      slotSizeMinutes: 30,
    },
  ],
  delays: [
    {
      storeName: 'Сільпо 1998',
      supplierName: 'ТОВ «Молочний Дім»',
      delayed: 3,
      reason: 'Дорожній затор',
    },
  ],
};

describe('ANL-11 — експорт CSV', () => {
  it('екранує коми, лапки й переноси рядків', () => {
    expect(escapeCsvCell('просп. Івасюка, 46')).toBe('"просп. Івасюка, 46"');
    expect(escapeCsvCell('ТОВ "Дім"')).toBe('"ТОВ ""Дім"""');
    expect(escapeCsvCell(null)).toBe('');
    expect(escapeCsvCell(12)).toBe('12');
  });

  it('додає BOM і рядок фільтрів окремим заголовком', () => {
    const csv = buildWidgetCsv('utilization', dashboard, filter);
    expect(csv.startsWith(UTF8_BOM)).toBe(true);
    const lines = csv.slice(UTF8_BOM.length).split('\r\n');
    expect(lines[0]).toContain('Період: 2026-08-01 — 2026-08-27');
    expect(lines[0]).toContain('Міста: Київ');
    expect(lines[0]).toContain('Магазини: усі');
    expect(lines[1]).toBe('Магазин,Місто,"Заброньовано, хв","Доступно, хв",Утилізація');
  });

  it('експорт відтворює рівно ту вибірку, що на екрані', () => {
    const csv = buildWidgetCsv('deliveries', dashboard, filter);
    const rows = csv.slice(UTF8_BOM.length).split('\r\n');
    // рядок фільтрів + заголовок + 1 рядок даних
    expect(rows).toHaveLength(3);
    expect(rows[2]).toBe('ТОВ «Молочний Дім»,4,9,1,2');
  });

  it('формує CSV для кожного дашборда', () => {
    for (const widget of ['utilization', 'deliveries', 'noShow', 'unloading', 'delays'] as const) {
      const csv = buildWidgetCsv(widget, dashboard, filter);
      expect(csv.slice(UTF8_BOM.length).split('\r\n')).toHaveLength(3);
    }
  });

  it('заголовок фільтрів позначає «усі», коли фільтр порожній', () => {
    expect(
      filterHeader({ from: '2026-08-01', to: '2026-08-02', cities: [], storeIds: [], supplierIds: [] }),
    ).toBe(
      'Період: 2026-08-01 — 2026-08-02 | Міста: усі | Магазини: усі | Постачальники: усі',
    );
  });

  it('без рядка фільтрів CSV містить лише заголовок і дані', () => {
    const csv = buildCsv([{ a: 1 }], [{ header: 'A', value: (r) => r.a }]);
    expect(csv).toBe(`${UTF8_BOM}A\r\n1`);
  });
});

describe('ANL-10 — пресети періоду', () => {
  it('«сьогодні» повертає один день', () => {
    expect(presetRange('today', '2026-08-27', addDays)).toEqual({
      from: '2026-08-27',
      to: '2026-08-27',
    });
  });

  it('«7 днів» і «30 днів» включають поточний день', () => {
    // 7 днів включно: 21…27 серпня
    expect(presetRange('7d', '2026-08-27', addDays)).toEqual({
      from: '2026-08-21',
      to: '2026-08-27',
    });
    // 30 днів включно: 29 липня…27 серпня
    expect(presetRange('30d', '2026-08-27', addDays)).toEqual({
      from: '2026-07-29',
      to: '2026-08-27',
    });
  });
});
