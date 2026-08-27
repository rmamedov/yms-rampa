import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AnalyticsDashboard,
  AnalyticsFilter,
  Booking,
  DelayRow,
  NoShowRow,
  Store,
  SupplierDeliveryRow,
  UnloadingTimeRow,
  UtilizationRow,
} from '../../models';
import { AnalyticsApi } from '../analytics.api';
import { MockDb, createRandom, DELAY_REASONS } from './mock-db';
import { MOCK_LATENCY, respond } from './mock-support';
import { diffDays } from '../../utils/time.util';
import { AuthService } from '../../auth/auth.service';

/** ANL-10: фільтри застосовуються до всіх віджетів дашборда одночасно. */
export function matchesAnalyticsFilter(
  booking: Booking,
  store: Store | undefined,
  filter: AnalyticsFilter,
): boolean {
  if (!store) {
    return false;
  }
  if (diffDays(filter.from, booking.date) < 0) {
    return false;
  }
  if (diffDays(booking.date, filter.to) < 0) {
    return false;
  }
  if (filter.cities.length > 0 && !filter.cities.includes(store.city)) {
    return false;
  }
  if (filter.storeIds.length > 0 && !filter.storeIds.includes(store.id)) {
    return false;
  }
  if (
    filter.supplierIds.length > 0 &&
    !filter.supplierIds.includes(booking.supplierId)
  ) {
    return false;
  }
  return true;
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

  dashboard(filter: AnalyticsFilter): Observable<AnalyticsDashboard> {
    return respond(() => {
      const allowed = this.allowedStoreIds();
      const storeById = new Map(this.db.state.stores.map((s) => [s.id, s]));
      const bookings = this.db.state.bookings.filter(
        (b) =>
          (allowed === null || allowed.includes(b.storeId)) &&
          matchesAnalyticsFilter(b, storeById.get(b.storeId), filter),
      );
      return {
        recalculatedAt: new Date(Date.now() - 25_000).toISOString(),
        utilization: buildUtilization(bookings, storeById),
        deliveries: buildDeliveries(bookings),
        noShow: buildNoShow(bookings, storeById),
        unloading: buildUnloading(bookings, storeById),
        delays: buildDelays(bookings, storeById),
      };
    }, this.latency);
  }
}

/** KPI-01: утилізація = заброньовані хвилини / доступні хвилини сітки. */
function buildUtilization(
  bookings: readonly Booking[],
  storeById: ReadonlyMap<string, Store>,
): UtilizationRow[] {
  const grouped = new Map<string, Booking[]>();
  for (const booking of bookings) {
    const list = grouped.get(booking.storeId) ?? [];
    list.push(booking);
    grouped.set(booking.storeId, list);
  }
  const rows: UtilizationRow[] = [];
  for (const [storeId, list] of grouped) {
    const store = storeById.get(storeId);
    if (!store || store.slotSizeMinutes === null) {
      continue;
    }
    const enabledRamps = Math.max(1, store.ramps.filter((r) => r.enabled).length);
    const dailyMinutes = store.receivingWindows.reduce(
      (sum, w) =>
        sum +
        w.intervals.reduce(
          (acc, i) => acc + minutes(i.to) - minutes(i.from),
          0,
        ),
      0,
    );
    const days = new Set(list.map((b) => b.date)).size || 1;
    const available = Math.max(
      store.slotSizeMinutes,
      Math.round((dailyMinutes / 7) * days * enabledRamps),
    );
    const booked = list.filter((b) => b.status !== 'cancelled').length *
      store.slotSizeMinutes;
    rows.push({
      storeId,
      storeName: store.displayName,
      city: store.city,
      bookedSlotMinutes: booked,
      availableSlotMinutes: available,
      utilization: Math.min(1, Math.round((booked / available) * 1000) / 1000),
    });
  }
  return rows.sort((a, b) => b.utilization - a.utilization).slice(0, 25);
}

function buildDeliveries(bookings: readonly Booking[]): SupplierDeliveryRow[] {
  const grouped = new Map<string, { name: string; rows: Booking[] }>();
  for (const booking of bookings) {
    const entry = grouped.get(booking.supplierId) ?? {
      name: booking.supplierName,
      rows: [],
    };
    entry.rows.push(booking);
    grouped.set(booking.supplierId, entry);
  }
  return [...grouped.entries()]
    .map(([supplierId, entry]) => ({
      supplierId,
      supplierName: entry.name,
      booked: entry.rows.filter((b) => b.status === 'booked').length,
      completed: entry.rows.filter((b) => b.status === 'completed').length,
      cancelled: entry.rows.filter((b) => b.status === 'cancelled').length,
      noShow: entry.rows.filter((b) => b.status === 'no_show').length,
    }))
    .sort(
      (a, b) =>
        b.booked + b.completed - (a.booked + a.completed) ||
        a.supplierName.localeCompare(b.supplierName, 'uk'),
    )
    .slice(0, 15);
}

function buildNoShow(
  bookings: readonly Booking[],
  storeById: ReadonlyMap<string, Store>,
): NoShowRow[] {
  const grouped = new Map<string, Booking[]>();
  for (const booking of bookings) {
    const key = `${booking.supplierId}|${booking.storeId}`;
    const list = grouped.get(key) ?? [];
    list.push(booking);
    grouped.set(key, list);
  }
  return [...grouped.entries()]
    .map(([key, list]) => {
      const [supplierId, storeId] = key.split('|');
      const noShow = list.filter((b) => b.status === 'no_show').length;
      return {
        supplierId,
        supplierName: list[0].supplierName,
        storeName: storeById.get(storeId)?.displayName ?? storeId,
        noShow,
        total: list.length,
        share: list.length === 0 ? 0 : Math.round((noShow / list.length) * 1000) / 1000,
      };
    })
    .filter((row) => row.noShow > 0)
    .sort((a, b) => b.share - a.share)
    .slice(0, 20);
}

function buildUnloading(
  bookings: readonly Booking[],
  storeById: ReadonlyMap<string, Store>,
): UnloadingTimeRow[] {
  const rnd = createRandom(4242);
  const grouped = new Map<string, number>();
  for (const booking of bookings) {
    if (booking.status !== 'completed') {
      continue;
    }
    grouped.set(booking.storeId, (grouped.get(booking.storeId) ?? 0) + 1);
  }
  return [...grouped.keys()]
    .map((storeId) => {
      const store = storeById.get(storeId);
      const slot = store?.slotSizeMinutes ?? 30;
      const avg = Math.round(slot * (0.6 + rnd() * 0.8));
      return {
        storeId,
        storeName: store?.displayName ?? storeId,
        avgMinutes: avg,
        medianMinutes: Math.max(5, avg - Math.round(rnd() * 6)),
        slotSizeMinutes: slot,
      };
    })
    .sort((a, b) => b.avgMinutes - a.avgMinutes)
    .slice(0, 20);
}

function buildDelays(
  bookings: readonly Booking[],
  storeById: ReadonlyMap<string, Store>,
): DelayRow[] {
  const rnd = createRandom(777);
  const rows: DelayRow[] = [];
  const grouped = new Map<string, Booking[]>();
  for (const booking of bookings) {
    const key = `${booking.storeId}|${booking.supplierId}`;
    const list = grouped.get(key) ?? [];
    list.push(booking);
    grouped.set(key, list);
  }
  for (const [key, list] of grouped) {
    const [storeId] = key.split('|');
    const delayed = Math.floor(rnd() * Math.max(1, list.length));
    if (delayed === 0) {
      continue;
    }
    rows.push({
      storeName: storeById.get(storeId)?.displayName ?? storeId,
      supplierName: list[0].supplierName,
      delayed,
      reason: DELAY_REASONS[Math.floor(rnd() * DELAY_REASONS.length)],
    });
  }
  return rows.sort((a, b) => b.delayed - a.delayed).slice(0, 20);
}

function minutes(time: string): number {
  const [h, m] = time.split(':');
  return Number(h) * 60 + Number(m);
}
