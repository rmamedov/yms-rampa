import {
  AnalyticsDashboard,
  AnalyticsFilter,
  AnalyticsWidgetId,
} from '../../core/models';
import { buildCsv, CsvColumn } from '../../core/utils/csv.util';

/** ANL-11: рядок-заголовок з застосованими фільтрами. */
export function filterHeader(filter: AnalyticsFilter): string {
  const parts = [
    `Період: ${filter.from} — ${filter.to}`,
    `Міста: ${filter.cities.length ? filter.cities.join('; ') : 'усі'}`,
    `Магазини: ${filter.storeIds.length ? filter.storeIds.join('; ') : 'усі'}`,
    `Постачальники: ${
      filter.supplierIds.length ? filter.supplierIds.join('; ') : 'усі'
    }`,
  ];
  return parts.join(' | ');
}

function percent(value: number): string {
  return `${(value * 100).toFixed(1).replace('.', ',')}%`;
}

/** ANL-11: експорт відтворює рівно ту вибірку, що на екрані. */
export function buildWidgetCsv(
  widget: AnalyticsWidgetId,
  dashboard: AnalyticsDashboard,
  filter: AnalyticsFilter,
): string {
  const header = filterHeader(filter);
  switch (widget) {
    case 'utilization': {
      const columns: CsvColumn<AnalyticsDashboard['utilization'][number]>[] = [
        { header: 'Магазин', value: (r) => r.storeName },
        { header: 'Місто', value: (r) => r.city },
        { header: 'Заброньовано, хв', value: (r) => r.bookedSlotMinutes },
        { header: 'Доступно, хв', value: (r) => r.availableSlotMinutes },
        { header: 'Утилізація', value: (r) => percent(r.utilization) },
      ];
      return buildCsv(dashboard.utilization, columns, header);
    }
    case 'deliveries': {
      const columns: CsvColumn<AnalyticsDashboard['deliveries'][number]>[] = [
        { header: 'Постачальник', value: (r) => r.supplierName },
        { header: 'Заброньовано', value: (r) => r.booked },
        { header: 'Виконано', value: (r) => r.completed },
        { header: 'Скасовано', value: (r) => r.cancelled },
        { header: 'No-show', value: (r) => r.noShow },
      ];
      return buildCsv(dashboard.deliveries, columns, header);
    }
    case 'noShow': {
      const columns: CsvColumn<AnalyticsDashboard['noShow'][number]>[] = [
        { header: 'Постачальник', value: (r) => r.supplierName },
        { header: 'Магазин', value: (r) => r.storeName },
        { header: 'No-show', value: (r) => r.noShow },
        { header: 'Усього', value: (r) => r.total },
        { header: 'Частка', value: (r) => percent(r.share) },
      ];
      return buildCsv(dashboard.noShow, columns, header);
    }
    case 'unloading': {
      const columns: CsvColumn<AnalyticsDashboard['unloading'][number]>[] = [
        { header: 'Магазин', value: (r) => r.storeName },
        { header: 'Середнє, хв', value: (r) => r.avgMinutes },
        { header: 'Медіана, хв', value: (r) => r.medianMinutes },
        { header: 'Розмір слоту, хв', value: (r) => r.slotSizeMinutes },
      ];
      return buildCsv(dashboard.unloading, columns, header);
    }
    case 'delays': {
      const columns: CsvColumn<AnalyticsDashboard['delays'][number]>[] = [
        { header: 'Магазин', value: (r) => r.storeName },
        { header: 'Постачальник', value: (r) => r.supplierName },
        { header: 'Затримок', value: (r) => r.delayed },
        { header: 'Причина', value: (r) => r.reason },
      ];
      return buildCsv(dashboard.delays, columns, header);
    }
  }
}

/** Пресети періоду (ANL-10). */
export function presetRange(
  preset: 'today' | '7d' | '30d',
  today: string,
  addDays: (date: string, days: number) => string,
): { from: string; to: string } {
  switch (preset) {
    case 'today':
      return { from: today, to: today };
    case '7d':
      return { from: addDays(today, -6), to: today };
    case '30d':
      return { from: addDays(today, -29), to: today };
  }
}
