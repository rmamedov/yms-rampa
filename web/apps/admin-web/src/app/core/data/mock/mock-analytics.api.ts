import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AnalyticsBreakdown,
  AnalyticsDimension,
  AnalyticsExportDataset,
  AnalyticsFilter,
  AnalyticsKpi,
  BreakdownRow,
  KpiSummary,
} from '../../models';
import { AnalyticsApi } from '../analytics.api';
import { MockBookingFact, MockDb, MockStore } from './mock-db';
import { fail, MOCK_LATENCY, respond } from './mock-support';
import { diffDays } from '../../utils/time.util';
import { AuthService } from '../../auth/auth.service';

/** AnalyticsController::NO_DATA_MESSAGE (ANL-13). */
export const NO_DATA_MESSAGE = 'Немає даних за обраний період';

/** AnalyticsQueryFactory::MAX_PERIOD_DAYS. */
export const MAX_PERIOD_DAYS = 366;

/** KpiSummary::TARGET_* analytics-service. */
export const KPI_TARGETS = {
  utilizationPercent: 60,
  onTimePercent: 85,
  medianWaitingMinutes: 20,
  noShowPercent: 5,
} as const;

function round(value: number): number {
  return Math.round(value * 100) / 100;
}

function median(values: readonly number[]): number {
  if (values.length === 0) {
    return 0;
  }
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 0
    ? (sorted[mid - 1] + sorted[mid]) / 2
    : sorted[mid];
}

function average(values: readonly number[]): number {
  return values.length === 0
    ? 0
    : values.reduce((sum, v) => sum + v, 0) / values.length;
}

function tally(values: readonly string[]): Record<string, number> {
  const result: Record<string, number> = {};
  for (const value of values) {
    result[value] = (result[value] ?? 0) + 1;
  }
  return result;
}

/** Описовий рядок фільтрів — AnalyticsQuery::describe(). */
export function describeFilter(filter: AnalyticsFilter): string {
  const parts = [`період: ${filter.from} — ${filter.to} (UTC)`];
  if (filter.cities.length > 0) parts.push(`міста: ${filter.cities.join('|')}`);
  if (filter.storeIds.length > 0) parts.push(`магазини: ${filter.storeIds.join('|')}`);
  if (filter.supplierIds.length > 0)
    parts.push(`постачальники: ${filter.supplierIds.join('|')}`);
  return parts.join('; ');
}

export function matchesAnalyticsFilter(
  fact: MockBookingFact,
  filter: AnalyticsFilter,
): boolean {
  const date = fact.slotStart.slice(0, 10);
  if (diffDays(filter.from, date) < 0 || diffDays(date, filter.to) < 0) {
    return false;
  }
  if (filter.cities.length > 0 && !filter.cities.includes(fact.city)) {
    return false;
  }
  if (filter.storeIds.length > 0 && !filter.storeIds.includes(fact.storeId)) {
    return false;
  }
  if (filter.supplierIds.length > 0 && !filter.supplierIds.includes(fact.supplierId)) {
    return false;
  }
  return true;
}

/** KPI-01…KPI-04 + ANL-04 у формі KpiSummary::toArray(). */
export function buildKpi(
  facts: readonly MockBookingFact[],
  availableMinutes: number,
): KpiSummary {
  const counted = facts.filter((f) => f.status !== 'cancelled');
  const bookedMinutes = counted.reduce((sum, f) => sum + f.slotMinutes, 0);
  const slotSizes = counted.map((f) => f.slotMinutes);
  const averageSlot = average(slotSizes);

  const onTimeFacts = facts.filter((f) => f.onTime !== null);
  const onTimeCount = onTimeFacts.filter((f) => f.onTime === true).length;
  const lateCount = onTimeFacts.filter((f) => f.onTime === false).length;

  const waiting = facts
    .map((f) => f.waitingMinutes)
    .filter((v): v is number => v !== null);
  const unloading = facts
    .map((f) => f.unloadingMinutes)
    .filter((v): v is number => v !== null);

  const noShowCount = facts.filter((f) => f.status === 'no_show').length;
  const cancelled = facts.filter((f) => f.status === 'cancelled').length;
  const noShowTotal = facts.length - cancelled;

  return {
    kpi01_rampUtilization: {
      bookedMinutes: round(bookedMinutes),
      availableMinutes: round(availableMinutes),
      utilizationPercent:
        availableMinutes === 0 ? 0 : round((bookedMinutes / availableMinutes) * 100),
      slotsCounted: counted.length,
    },
    kpi02_onTimeDelivery: {
      onTimeCount,
      totalCount: onTimeFacts.length,
      onTimePercent:
        onTimeFacts.length === 0
          ? 0
          : round((onTimeCount / onTimeFacts.length) * 100),
      earlyCount: 0,
      lateCount,
      withoutArrivalCount: facts.length - onTimeFacts.length,
    },
    kpi03_waitingTime: {
      averageMinutes: round(average(waiting)),
      medianMinutes: round(median(waiting)),
      sampleSize: waiting.length,
    },
    kpi04_noShowRate: {
      noShowCount,
      totalCount: noShowTotal,
      noShowPercent: noShowTotal === 0 ? 0 : round((noShowCount / noShowTotal) * 100),
      cancelledExcluded: cancelled,
    },
    anl04_unloadingTime: {
      averageMinutes: round(average(unloading)),
      medianMinutes: round(median(unloading)),
      sampleSize: unloading.length,
      averageSlotMinutes: round(averageSlot),
    },
    counters: {
      total: facts.length,
      byStatus: tally(facts.map((f) => f.status)),
      byType: tally(facts.map((f) => f.type)),
      byRejectionReason: tally(
        facts.map((f) => f.rejectedReason).filter((v): v is string => v !== null),
      ),
      byDelayReason: tally(
        facts.map((f) => f.delayReason).filter((v): v is string => v !== null),
      ),
      delayedCount: facts.filter((f) => f.delayed).length,
      partialUnloadCount: facts.filter(
        (f) => f.status === 'completed' && f.unloadedPalletsCount < f.palletsCount,
      ).length,
      plannedPallets: facts.reduce((sum, f) => sum + f.palletsCount, 0),
      unloadedPallets: facts.reduce((sum, f) => sum + f.unloadedPalletsCount, 0),
    },
    targets: { ...KPI_TARGETS },
  };
}

const DIMENSION_LABELS: Readonly<Record<AnalyticsDimension, string>> = {
  network: 'Мережа',
  city: 'Місто',
  store: 'Магазин',
  ramp: 'Рампа',
  supplier: 'Постачальник',
  day: 'День',
  week: 'Тиждень',
  month: 'Місяць',
  type: 'Тип бронювання',
  rejection_reason: 'Причина відмови',
};

function dimensionKey(fact: MockBookingFact, dimension: AnalyticsDimension): string {
  switch (dimension) {
    case 'network':
      return 'network';
    case 'city':
      return fact.city;
    case 'store':
      return fact.storeId;
    case 'ramp':
      return `${fact.storeId}:${fact.rampId}`;
    case 'supplier':
      return fact.supplierId;
    case 'day':
      return fact.slotStart.slice(0, 10);
    case 'week':
      return fact.slotStart.slice(0, 7);
    case 'month':
      return fact.slotStart.slice(0, 7);
    case 'type':
      return fact.type;
    case 'rejection_reason':
      return fact.rejectedReason ?? 'none';
  }
}

@Injectable()
export class MockAnalyticsApi extends AnalyticsApi {
  private readonly db = inject(MockDb);
  private readonly auth = inject(AuthService);
  private readonly latency = inject(MOCK_LATENCY);

  /**
   * ANL-12 / RBAC-13: для store_manager дані обмежені його магазинами;
   * порожній масив скоупа означає нуль доступу, а не «вся мережа».
   */
  private allowedStoreIds(): readonly string[] | null {
    return this.auth.grant('analytics.view') === 'scoped'
      ? this.auth.storeIds()
      : null;
  }

  private periodProblem(filter: AnalyticsFilter): { code: string; detail: string } | null {
    if (!filter.from || !filter.to) {
      return {
        code: 'ANALYTICS_INVALID_PERIOD',
        detail: 'Не вказано період: потрібні параметри from і to (або preset).',
      };
    }
    const days = diffDays(filter.from, filter.to);
    if (days < 0) {
      return {
        code: 'ANALYTICS_INVALID_PERIOD',
        detail: 'Початок періоду не може бути пізнішим за кінець.',
      };
    }
    if (days + 1 > MAX_PERIOD_DAYS) {
      return {
        code: 'ANALYTICS_PERIOD_TOO_LONG',
        detail: `Період задовгий: ${days + 1} діб, максимум ${MAX_PERIOD_DAYS}.`,
      };
    }
    return null;
  }

  private facts(filter: AnalyticsFilter): readonly MockBookingFact[] {
    const allowed = this.allowedStoreIds();
    return this.db.state.bookings.filter(
      (f) =>
        (allowed === null || allowed.includes(f.storeId)) &&
        matchesAnalyticsFilter(f, filter),
    );
  }

  /** Доступні хвилини сітки — з чинних конфігурацій магазинів у вибірці. */
  private availableMinutes(
    filter: AnalyticsFilter,
    storeIds: readonly string[],
  ): number {
    const days = Math.max(1, diffDays(filter.from, filter.to) + 1);
    let total = 0;
    for (const id of storeIds) {
      const store: MockStore | undefined = this.db.store(id);
      const config = store?.configurations[store.configurations.length - 1];
      if (!config) {
        continue;
      }
      const weekly = config.receivingWindows.reduce(
        (sum, w) =>
          sum +
          w.intervals.reduce(
            (acc, i) => acc + minutesOf(i.to) - minutesOf(i.from),
            0,
          ),
        0,
      );
      const ramps = Math.max(1, config.ramps.filter((r) => r.enabled).length);
      total += (weekly / 7) * days * ramps;
    }
    return Math.round(total);
  }

  kpi(filter: AnalyticsFilter): Observable<AnalyticsKpi> {
    const problem = this.periodProblem(filter);
    if (problem) {
      return fail(422, problem, this.latency);
    }
    return respond(() => {
      const facts = this.facts(filter);
      const storeIds = [...new Set(facts.map((f) => f.storeId))];
      const kpi = buildKpi(facts, this.availableMinutes(filter, storeIds));
      return {
        filters: describeFilter(filter),
        kpi,
        recalculatedAt: new Date(Date.now() - 25_000).toISOString(),
        empty: facts.length === 0,
        message: facts.length === 0 ? NO_DATA_MESSAGE : null,
      };
    }, this.latency);
  }

  breakdown(
    filter: AnalyticsFilter,
    dimension: AnalyticsDimension,
  ): Observable<AnalyticsBreakdown> {
    const problem = this.periodProblem(filter);
    if (problem) {
      return fail(422, problem, this.latency);
    }
    return respond(() => {
      const facts = this.facts(filter);
      const grouped = new Map<string, MockBookingFact[]>();
      for (const fact of facts) {
        const key = dimensionKey(fact, dimension);
        const list = grouped.get(key) ?? [];
        list.push(fact);
        grouped.set(key, list);
      }
      const rows: BreakdownRow[] = [...grouped.entries()]
        .map(([key, list]) => ({
          dimension,
          key,
          kpi: buildKpi(
            list,
            this.availableMinutes(filter, [
              ...new Set(list.map((f) => f.storeId)),
            ]),
          ),
        }))
        .sort((a, b) => b.kpi.counters.total - a.kpi.counters.total);
      return {
        filters: describeFilter(filter),
        dimension,
        dimensionLabel: DIMENSION_LABELS[dimension],
        rows,
        recalculatedAt: new Date(Date.now() - 25_000).toISOString(),
        empty: rows.length === 0,
        message: rows.length === 0 ? NO_DATA_MESSAGE : null,
      };
    }, this.latency);
  }

  /** ANL-11: CSV з рядком фільтрів попереду — як AnalyticsCsvView. */
  exportCsv(
    filter: AnalyticsFilter,
    dataset: AnalyticsExportDataset,
    dimension: AnalyticsDimension,
  ): Observable<string> {
    const problem = this.periodProblem(filter);
    if (problem) {
      return fail(422, problem, this.latency);
    }
    return respond(() => {
      const facts = this.facts(filter);
      if (dataset === 'bookings') {
        return csv(
          describeFilter(filter),
          [
            'bookingId',
            'Магазин',
            'Місто',
            'Постачальник',
            'Рампа',
            'Початок слоту (UTC)',
            'Кінець слоту (UTC)',
            'Тип',
            'Статус',
            'Очікування, хв',
            'Розвантаження, хв',
            'Палет заплановано',
            'Палет розвантажено',
            'Затримка',
          ],
          facts.map((f) => [
            f.bookingId,
            f.storeId,
            f.city,
            f.supplierId,
            f.rampId,
            f.slotStart,
            f.slotEnd,
            f.type,
            f.status,
            f.waitingMinutes,
            f.unloadingMinutes,
            f.palletsCount,
            f.unloadedPalletsCount,
            f.delayed,
          ]),
        );
      }
      const grouped = new Map<string, MockBookingFact[]>();
      for (const fact of facts) {
        const key = dimensionKey(fact, dimension);
        const list = grouped.get(key) ?? [];
        list.push(fact);
        grouped.set(key, list);
      }
      return csv(
        describeFilter(filter),
        [
          'Розріз',
          'Ключ',
          'Бронювань',
          'KPI-01 утилізація, %',
          'KPI-02 у слот, %',
          'KPI-03 очікування медіана, хв',
          'KPI-04 no-show, %',
        ],
        [...grouped.entries()].map(([key, list]) => {
          const kpi = buildKpi(
            list,
            this.availableMinutes(filter, [...new Set(list.map((f) => f.storeId))]),
          );
          return [
            DIMENSION_LABELS[dimension],
            key,
            kpi.counters.total,
            kpi.kpi01_rampUtilization.utilizationPercent,
            kpi.kpi02_onTimeDelivery.onTimePercent,
            kpi.kpi03_waitingTime.medianMinutes,
            kpi.kpi04_noShowRate.noShowPercent,
          ];
        }),
      );
    }, this.latency);
  }
}

function minutesOf(time: string): number {
  const [h, m] = time.split(':');
  return Number(h) * 60 + Number(m);
}

function csvCell(value: unknown): string {
  const text =
    value === null || value === undefined
      ? ''
      : typeof value === 'boolean'
        ? value
          ? 'так'
          : 'ні'
        : String(value);
  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function csv(
  filtersLine: string,
  headers: readonly string[],
  rows: ReadonlyArray<readonly unknown[]>,
): string {
  const lines = [
    csvCell(`Фільтри: ${filtersLine}`),
    headers.map(csvCell).join(','),
    ...rows.map((row) => row.map(csvCell).join(',')),
  ];
  return `${lines.join('\n')}\n`;
}
