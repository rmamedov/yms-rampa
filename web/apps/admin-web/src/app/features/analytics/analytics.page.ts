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
  ANALYTICS_DIMENSIONS,
  AnalyticsBreakdown,
  AnalyticsDimension,
  AnalyticsExportDataset,
  AnalyticsFilter,
  AnalyticsKpi,
  StoreListRow,
  Supplier,
} from '../../core/models';
import { AnalyticsApi } from '../../core/data/analytics.api';
import { StoresApi } from '../../core/data/stores.api';
import { SuppliersApi } from '../../core/data/suppliers.api';
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
import { AnalyticsPreset, presetRange } from './analytics-export';
import { DEFAULT_STORE_FILTER } from '../../core/utils/query-state.util';

/**
 * Дашборд аналітики. Форма даних — рівно та, що віддає analytics-service:
 * зведення KPI (/analytics/kpi) і розріз (/analytics/breakdown?dimension=…).
 * Період обовʼязковий — без from/to бекенд відповідає 422.
 */
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
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly from = signal(addDays(kyivDate(), -29));
  protected readonly to = signal(kyivDate());
  protected readonly cities = signal<readonly string[]>([]);
  protected readonly storeIds = signal<readonly string[]>([]);
  protected readonly supplierIds = signal<readonly string[]>([]);
  protected readonly dimension = signal<AnalyticsDimension>('store');

  protected readonly cityOptions = signal<readonly SelectOption[]>([]);
  protected readonly storeOptions = signal<readonly SelectOption[]>([]);
  protected readonly supplierOptions = signal<readonly SelectOption[]>([]);
  protected readonly dimensions = ANALYTICS_DIMENSIONS;

  protected readonly kpi = signal<AnalyticsKpi | null>(null);
  protected readonly breakdown = signal<AnalyticsBreakdown | null>(null);
  protected readonly loading = signal(false);

  protected readonly formatDateTime = formatDateTime;

  /** ANL-12: для analyst усе read-only. */
  protected readonly readOnly = computed(() => this.auth.role() === 'analyst');

  /** ANL-13: стан «Немає даних за обраний період» визначає бекенд. */
  protected readonly hasData = computed(() => this.kpi()?.empty === false);
  protected readonly noDataMessage = computed(() => this.kpi()?.message ?? null);
  protected readonly recalculatedAt = computed(
    () => this.kpi()?.recalculatedAt ?? null,
  );

  protected readonly counters = computed(() => this.kpi()?.kpi.counters ?? null);
  protected readonly targets = computed(() => this.kpi()?.kpi.targets ?? null);
  protected readonly utilization = computed(
    () => this.kpi()?.kpi.kpi01_rampUtilization ?? null,
  );
  protected readonly onTime = computed(
    () => this.kpi()?.kpi.kpi02_onTimeDelivery ?? null,
  );
  protected readonly waiting = computed(() => this.kpi()?.kpi.kpi03_waitingTime ?? null);
  protected readonly noShow = computed(() => this.kpi()?.kpi.kpi04_noShowRate ?? null);
  protected readonly unloading = computed(
    () => this.kpi()?.kpi.anl04_unloadingTime ?? null,
  );

  constructor() {
    this.storesApi
      .cities()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (cities) =>
          this.cityOptions.set(
            cities.map((c) => ({ value: c.city, label: c.city })),
          ),
        error: () => this.cityOptions.set([]),
      });

    this.loadAllStoreOptions();

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
    const filter = this.filter();
    this.loading.set(true);
    this.api
      .kpi(filter)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (data) => {
          this.kpi.set(data);
          this.loading.set(false);
        },
        error: (error: unknown) => {
          this.loading.set(false);
          this.toast.error(error);
        },
      });

    this.api
      .breakdown(filter, this.dimension())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (data) => this.breakdown.set(data),
        error: () => this.breakdown.set(null),
      });
  }

  protected setDimension(event: Event): void {
    this.dimension.set(
      (event.target as HTMLSelectElement).value as AnalyticsDimension,
    );
    this.load();
  }

  protected applyPreset(preset: AnalyticsPreset): void {
    const range = presetRange(preset, kyivDate(), addDays);
    this.from.set(range.from);
    this.to.set(range.to);
    this.load();
  }

  /**
   * Показник може бути НЕ ВИЗНАЧЕНИЙ, і це нормальний стан: утилізація рамп
   * повертає null, поки немає інвентарю слото-хвилин. Раніше тут падало
   * `null.toFixed()`, і одне порожнє значення валило рендер УСЬОГО дашборда —
   * користувач бачив порожню сторінку замість решти KPI.
   */
  /**
   * Розріз приходить із бекенду лише з ідентифікатором рядка, тому в таблиці
   * стояли UUID. Назви беремо з довідників, які сторінка вже завантажила для
   * фільтрів; якщо назви немає — лишається ідентифікатор, це чесніше за порожньо.
   */
  protected rowLabel(dimension: string, key: string): string {
    const source =
      dimension === 'store'
        ? this.storeOptions()
        : dimension === 'supplier'
          ? this.supplierOptions()
          : this.cityOptions();

    return source.find((o) => o.value === key)?.label ?? key;
  }

  protected percent(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : `${value.toFixed(1)}%`;
  }

  protected barWidth(value: number | null | undefined): string {
    return `${Math.round(Math.min(100, Math.max(0, value ?? 0)))}%`;
  }

  /** ANL-11: CSV формує бекенд і віддає разом із рядком фільтрів. */
  protected exportCsv(dataset: AnalyticsExportDataset): void {
    this.api
      .exportCsv(this.filter(), dataset, this.dimension())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (csv) => {
          const name = csvFileName(`analytics-${dataset}`);
          downloadTextFile(csv, name);
          this.toast.success('analytics.export.done', { name });
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }
  /**
   * Фільтр за магазином має покривати всю мережу: один запит на 100 записів
   * із 455 означав, що більшість філій відфільтрувати неможливо.
   */
  private loadAllStoreOptions(): void {
    const pageSize = 100 as const;
    const collected: StoreListRow[] = [];

    const fetchPage = (page: number): void => {
      this.storesApi
        .list(DEFAULT_STORE_FILTER, { page, pageSize, sort: 'city' })
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (result) => {
            collected.push(...result.items);

            if (collected.length < result.total && result.items.length > 0) {
              fetchPage(page + 1);
              return;
            }

            this.storeOptions.set(
              collected
                .filter((row) => row.city?.trim())
                .map((row) => ({ value: row.id, label: `${row.externalId} — ${row.city}` })),
            );
          },
          error: () => this.storeOptions.set([]),
        });
    };

    fetchPage(1);
  }
}
