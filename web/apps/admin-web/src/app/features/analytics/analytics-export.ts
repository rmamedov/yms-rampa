/**
 * Пресети періоду дашборда (ANL-10) — ті самі, що приймає бекенд
 * у параметрі preset: today / 7d / 30d.
 *
 * Сам CSV формує analytics-service (GET /analytics/export.csv, ANL-11),
 * тож клієнтської збірки таблиці тут більше немає.
 */
export type AnalyticsPreset = 'today' | '7d' | '30d';

export const ANALYTICS_PRESETS: readonly AnalyticsPreset[] = ['today', '7d', '30d'];

export function presetRange(
  preset: AnalyticsPreset,
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
