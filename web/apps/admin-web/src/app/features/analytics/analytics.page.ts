import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  AnalyticsDashboard,
  AnalyticsFilter,
  AnalyticsWidgetId,
  StoreListRow,
  Supplier,
} from '../../core/models';
import { AnalyticsApi } from '../../core/data/analytics.api';
import { StoresApi } from '../../core/data/stores.api';
import { SuppliersApi } from '../../core/data/suppliers.api';
import { AuditApi } from '../../core/data/audit.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import {
  MultiSelectComponent,
  SelectOption,
} from '../../shared/ui/multi-select.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { addDays, formatDateTime, kyivDate } from '../../core/utils/time.util';
import { csvFileName } from '../../core/utils/csv.util';
import { downloadTextFile } from '../../core/utils/download.util';
import { buildWidgetCsv, presetRange } from './analytics-export';
import { DEFAULT_STORE_FILTER } from '../../core/utils/query-state.util';

@Component({
  selector: 'app-analytics-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, MultiSelectComponent, EmptyStateComponent],
  templateUrl: './analytics.page.html',
})
export class AnalyticsPage {
  private readonly api = inject(AnalyticsApi);
  private readonly storesApi = inject(StoresApi);
  private readonly suppliersApi = inject(SuppliersApi);
  private readonly auditApi = inject(AuditApi);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly from = signal(addDays(kyivDate(), -29));
  protected readonly to = signal(kyivDate());
  protected readonly cities = signal<readonly string[]>([]);
  protected readonly storeIds = signal<readonly string[]>([]);
  protected readonly supplierIds = signal<readonly string[]>([]);

  protected readonly cityOptions = signal<readonly SelectOption[]>([]);
  protected readonly storeOptions = signal<readonly SelectOption[]>([]);
  protected readonly supplierOptions = signal<readonly SelectOption[]>([]);

  protected readonly dashboard = signal<AnalyticsDashboard | null>(null);
  protected readonly loading = signal(false);

  protected readonly formatDateTime = formatDateTime;

  /** ANL-12: для analyst усе read-only. */
  protected readonly readOnly = computed(() => this.auth.role() === 'analyst');

  protected readonly hasData = computed(() => {
    const data = this.dashboard();
    if (!data) {
      return false;
    }
    return (
      data.utilization.length > 0 ||
      data.deliveries.length > 0 ||
      data.noShow.length > 0 ||
      data.unloading.length > 0 ||
      data.delays.length > 0
    );
  });

  protected readonly totalBookings = computed(() =>
    (this.dashboard()?.deliveries ?? []).reduce(
      (sum, row) => sum + row.booked + row.completed,
      0,
    ),
  );
  protected readonly totalNoShow = computed(() =>
    (this.dashboard()?.deliveries ?? []).reduce((sum, row) => sum + row.noShow, 0),
  );
  protected readonly avgUtilization = computed(() => {
    const rows = this.dashboard()?.utilization ?? [];
    if (rows.length === 0) {
      return 0;
    }
    return rows.reduce((sum, r) => sum + r.utilization, 0) / rows.length;
  });

  constructor() {
    this.storesApi
      .cities()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (cities) =>
          this.cityOptions.set(cities.map((c) => ({ value: c, label: c }))),
        error: () => this.cityOptions.set([]),
      });

    this.storesApi
      .list(DEFAULT_STORE_FILTER, { page: 1, pageSize: 100, sort: 'city' })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (page) =>
          this.storeOptions.set(
            page.items.map((row: StoreListRow) => ({
              value: row.id,
              label: `${row.externalId} — ${row.city}`,
            })),
          ),
        error: () => this.storeOptions.set([]),
      });

    this.suppliersApi
      .all()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (suppliers) =>
          this.supplierOptions.set(
            suppliers.map((s: Supplier) => ({ value: s.id, label: s.name })),
          ),
        error: () => this.supplierOptions.set([]),
      });

    this.load();
  }

  protected filter(): AnalyticsFilter {
    return {
      from: this.from(),
      to: this.to(),
      cities: this.cities(),
      storeIds: this.storeIds(),
      supplierIds: this.supplierIds(),
    };
  }

  protected load(): void {
    this.loading.set(true);
    this.api
      .dashboard(this.filter())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (data) => {
          this.dashboard.set(data);
          this.loading.set(false);
        },
        error: (error: unknown) => {
          this.loading.set(false);
          this.toast.error(error);
        },
      });
  }

  protected applyPreset(preset: 'today' | '7d' | '30d'): void {
    const range = presetRange(preset, kyivDate(), addDays);
    this.from.set(range.from);
    this.to.set(range.to);
    this.load();
  }

  protected percent(value: number): string {
    return `${(value * 100).toFixed(1)}%`;
  }

  protected barWidth(value: number): string {
    return `${Math.round(Math.min(1, value) * 100)}%`;
  }

  /** ANL-11: експорт поточної вибірки у CSV з рядком фільтрів. */
  protected exportCsv(widget: AnalyticsWidgetId): void {
    const data = this.dashboard();
    if (!data) {
      return;
    }
    const csv = buildWidgetCsv(widget, data, this.filter());
    const name = csvFileName(`analytics-${widget}`);
    downloadTextFile(csv, name);
    this.toast.success('analytics.export.done', { name });
    this.auditApi
      .write({
        objectType: 'analytics',
        objectId: widget,
        objectLabel: widget,
        action: 'export',
        changes: [],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
  }
}
