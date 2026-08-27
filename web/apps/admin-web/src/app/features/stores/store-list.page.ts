import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import {
  BulkResultRow,
  PageSize,
  StoreListRow,
  YMS_STATUSES,
  YmsStatus,
} from '../../core/models';
import { StoresApi, ConfigTemplateId } from '../../core/data/stores.api';
import { AuditApi } from '../../core/data/audit.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { I18nService } from '../../core/i18n/i18n.service';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import {
  MultiSelectComponent,
  SelectOption,
} from '../../shared/ui/multi-select.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import {
  DEFAULT_STORE_STATE,
  hasActiveFilters,
  storeStateFromParams,
  storeStateToParams,
  TableState,
  toggleSort,
} from '../../core/utils/query-state.util';
import { formatDateTime } from '../../core/utils/time.util';

type BulkAction = 'status' | 'visibility' | 'template';

@Component({
  selector: 'app-store-list-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    TranslatePipe,
    PaginationComponent,
    MultiSelectComponent,
    EmptyStateComponent,
    ModalComponent,
  ],
  templateUrl: './store-list.page.html',
})
export class StoreListPage {
  private readonly api = inject(StoresApi);
  private readonly audit = inject(AuditApi);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly state = signal<TableState>(DEFAULT_STORE_STATE);
  protected readonly rows = signal<readonly StoreListRow[]>([]);
  protected readonly total = signal(0);
  protected readonly loading = signal(false);
  protected readonly cityOptions = signal<readonly SelectOption[]>([]);
  protected readonly selection = signal<readonly string[]>([]);
  protected readonly searchTerm = signal('');

  protected readonly bulkOpen = signal<BulkAction | null>(null);
  protected readonly bulkStatus = signal<YmsStatus>('paused');
  protected readonly bulkVisible = signal(true);
  protected readonly bulkTemplate = signal<ConfigTemplateId>('standard');
  protected readonly bulkResult = signal<readonly BulkResultRow[] | null>(null);

  protected readonly statusOptions: readonly SelectOption[] = YMS_STATUSES.map(
    (status) => ({ value: status, label: this.i18n.t(`ymsStatus.${status}`) }),
  );
  protected readonly templateOptions: readonly ConfigTemplateId[] = [
    'standard',
    'short',
  ];
  protected readonly bulkStatusOptions = YMS_STATUSES;

  protected readonly hasFilters = computed(() => hasActiveFilters(this.state().filter));
  protected readonly configuredValue = computed(() => {
    const configured = this.state().filter.configured;
    return configured === null ? '' : configured ? 'true' : 'false';
  });
  protected readonly allSelected = computed(
    () =>
      this.rows().length > 0 &&
      this.rows().every((row) => this.selection().includes(row.id)),
  );
  protected readonly canBulk = computed(
    () => this.auth.canConfigureStores() && this.selection().length > 0,
  );

  constructor() {
    this.route.queryParams
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((params) => {
        const next = storeStateFromParams(params);
        this.state.set(next);
        this.searchTerm.set(next.filter.search);
        this.load();
      });

    this.api
      .cities()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((cities) =>
        this.cityOptions.set(cities.map((city) => ({ value: city, label: city }))),
      );
  }

  protected formatDateTime = formatDateTime;

  protected statusBadge(status: YmsStatus): string {
    switch (status) {
      case 'active':
        return 'badge badge-success';
      case 'paused':
        return 'badge badge-warn';
      case 'archived':
        return 'badge badge-danger';
      default:
        return 'badge';
    }
  }

  protected load(): void {
    const state = this.state();
    this.loading.set(true);
    this.api
      .list(state.filter, {
        page: state.page,
        pageSize: state.pageSize,
        sort: state.sort,
        direction: state.direction,
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (page) => {
          this.rows.set(page.items);
          this.total.set(page.total);
          this.loading.set(false);
        },
        error: (error: unknown) => {
          this.loading.set(false);
          this.toast.error(error);
        },
      });
  }

  private navigate(patch: Partial<TableState>): void {
    const next: TableState = { ...this.state(), ...patch };
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: storeStateToParams(next),
    });
  }

  protected applySearch(): void {
    this.navigate({
      page: 1,
      filter: { ...this.state().filter, search: this.searchTerm().trim() },
    });
  }

  protected setCities(cities: readonly string[]): void {
    this.navigate({ page: 1, filter: { ...this.state().filter, cities } });
  }

  protected setStatuses(values: readonly string[]): void {
    this.navigate({
      page: 1,
      filter: { ...this.state().filter, statuses: values as readonly YmsStatus[] },
    });
  }

  protected setConfigured(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    const configured = raw === '' ? null : raw === 'true';
    this.navigate({ page: 1, filter: { ...this.state().filter, configured } });
  }

  protected resetFilters(): void {
    this.searchTerm.set('');
    this.navigate({ page: 1, filter: DEFAULT_STORE_STATE.filter });
  }

  protected sortBy(column: string): void {
    this.navigate(toggleSort(this.state(), column));
  }

  protected sortIndicator(column: string): string {
    if (this.state().sort !== column) {
      return '';
    }
    return this.state().direction === 'desc' ? ' ↓' : ' ↑';
  }

  protected setPage(page: number): void {
    this.navigate({ page });
  }

  protected setPageSize(pageSize: PageSize): void {
    this.navigate({ page: 1, pageSize });
  }

  protected toggleRow(id: string): void {
    this.selection.update((current) =>
      current.includes(id) ? current.filter((v) => v !== id) : [...current, id],
    );
  }

  protected toggleAll(): void {
    this.selection.set(this.allSelected() ? [] : this.rows().map((r) => r.id));
  }

  protected openBulk(action: BulkAction): void {
    this.bulkResult.set(null);
    this.bulkOpen.set(action);
  }

  protected confirmBulk(): void {
    const action = this.bulkOpen();
    const ids = this.selection();
    if (!action || ids.length === 0) {
      return;
    }
    const request$ =
      action === 'status'
        ? this.api.bulkStatus(ids, this.bulkStatus())
        : action === 'visibility'
          ? this.api.bulkVisibility(ids, this.bulkVisible())
          : this.api.applyTemplate(ids, this.bulkTemplate());

    request$.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (result) => {
        this.bulkResult.set(result);
        const ok = result.filter((r) => r.ok).length;
        this.toast.success('stores.bulk.result', {
          ok,
          failed: result.length - ok,
        });
        // ADM-04: окремий запис аудиту на кожен магазин
        for (const row of result.filter((r) => r.ok)) {
          this.audit
            .write({
              objectType: 'store',
              objectId: row.id,
              objectLabel: row.label,
              action: action === 'template' ? 'update' : 'status_change',
              changes: [],
            })
            .pipe(takeUntilDestroyed(this.destroyRef))
            .subscribe({ error: () => undefined });
        }
        this.load();
      },
      error: (error: unknown) => this.toast.error(error),
    });
  }

  protected closeBulk(): void {
    this.bulkOpen.set(null);
    this.bulkResult.set(null);
  }

  protected onBulkStatus(event: Event): void {
    this.bulkStatus.set((event.target as HTMLSelectElement).value as YmsStatus);
  }

  protected onBulkTemplate(event: Event): void {
    this.bulkTemplate.set(
      (event.target as HTMLSelectElement).value as ConfigTemplateId,
    );
  }
}
