import { Observable } from 'rxjs';
import { AnalyticsDashboard, AnalyticsFilter } from '../models';

/** analytics-service: read-моделі дашбордів (5.7). */
export abstract class AnalyticsApi {
  abstract dashboard(filter: AnalyticsFilter): Observable<AnalyticsDashboard>;
}
