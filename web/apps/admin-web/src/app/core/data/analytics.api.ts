import { Observable } from 'rxjs';
import {
  AnalyticsBreakdown,
  AnalyticsDimension,
  AnalyticsExportDataset,
  AnalyticsFilter,
  AnalyticsKpi,
} from '../models';

/**
 * analytics-service, staff-контур (усе read-only, ANL-12):
 *   GET /api/admin/v1/analytics/kpi
 *   GET /api/admin/v1/analytics/breakdown?dimension=…
 *   GET /api/admin/v1/analytics/export.csv?dataset=bookings|breakdown
 *
 * Період обовʼязковий: без from/to (або preset) бекенд відповідає
 * 422 з кодом ANALYTICS_INVALID_PERIOD.
 */
export abstract class AnalyticsApi {
  abstract kpi(filter: AnalyticsFilter): Observable<AnalyticsKpi>;
  abstract breakdown(
    filter: AnalyticsFilter,
    dimension: AnalyticsDimension,
  ): Observable<AnalyticsBreakdown>;
  /** ANL-11: CSV формує бекенд, разом із рядком застосованих фільтрів. */
  abstract exportCsv(
    filter: AnalyticsFilter,
    dataset: AnalyticsExportDataset,
    dimension: AnalyticsDimension,
  ): Observable<string>;
}
