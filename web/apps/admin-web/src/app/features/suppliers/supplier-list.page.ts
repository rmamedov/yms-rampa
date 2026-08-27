import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import {
  PageSize,
  Supplier,
  SupplierStatus,
} from '../../core/models';
import { SuppliersApi, SupplierFilter } from '../../core/data/suppliers.api';
import { ToastService } from '../../core/ui/toast.service';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { ModalComponent } from '../../shared/ui/modal.component';

@Component({
  selector: 'app-supplier-list-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    TranslatePipe,
    PaginationComponent,
    EmptyStateComponent,
    ModalComponent,
  ],
  templateUrl: './supplier-list.page.html',
})
export class SupplierListPage {
  private readonly api = inject(SuppliersApi);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly rows = signal<readonly Supplier[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);
  protected readonly sort = signal('name');
  protected readonly direction = signal<'asc' | 'desc'>('asc');
  protected readonly search = signal('');
  protected readonly statusFilter = signal<SupplierStatus | ''>('');
  protected readonly selection = signal<readonly string[]>([]);
  protected readonly bulkOpen = signal(false);
  protected readonly bulkStatus = signal<SupplierStatus>('suspended');

  protected readonly canManage = computed(() => this.auth.can('supplier.manage'));
  protected readonly allSelected = computed(
    () =>
      this.rows().length > 0 &&
      this.rows().every((row) => this.selection().includes(row.id)),
  );

  constructor() {
    this.load();
  }

  private filter(): SupplierFilter {
    return {
      search: this.search().trim(),
      statuses: this.statusFilter() === '' ? [] : [this.statusFilter() as SupplierStatus],
    };
  }

  protected load(): void {
    this.api
      .list(this.filter(), {
        page: this.page(),
        pageSize: this.pageSize(),
        sort: this.sort(),
        direction: this.direction(),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (page) => {
          this.rows.set(page.items);
          this.total.set(page.total);
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected applyFilters(): void {
    this.page.set(1);
    this.load();
  }

  protected setStatusFilter(event: Event): void {
    this.statusFilter.set(
      (event.target as HTMLSelectElement).value as SupplierStatus | '',
    );
    this.applyFilters();
  }

  protected sortBy(column: string): void {
    if (this.sort() === column) {
      this.direction.set(this.direction() === 'asc' ? 'desc' : 'asc');
    } else {
      this.sort.set(column);
      this.direction.set('asc');
    }
    this.page.set(1);
    this.load();
  }

  protected sortIndicator(column: string): string {
    if (this.sort() !== column) {
      return '';
    }
    return this.direction() === 'desc' ? ' ↓' : ' ↑';
  }

  protected setPage(page: number): void {
    this.page.set(page);
    this.load();
  }

  protected setPageSize(size: PageSize): void {
    this.pageSize.set(size);
    this.page.set(1);
    this.load();
  }

  protected toggleRow(id: string): void {
    this.selection.update((current) =>
      current.includes(id) ? current.filter((v) => v !== id) : [...current, id],
    );
  }

  protected toggleAll(): void {
    this.selection.set(this.allSelected() ? [] : this.rows().map((r) => r.id));
  }

  protected onBulkStatus(event: Event): void {
    this.bulkStatus.set((event.target as HTMLSelectElement).value as SupplierStatus);
  }

  protected confirmBulk(): void {
    this.api
      .bulkStatus(this.selection(), this.bulkStatus())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (result) => {
          const ok = result.filter((r) => r.ok).length;
          this.toast.success('stores.bulk.result', {
            ok,
            failed: result.length - ok,
          });
          this.bulkOpen.set(false);
          this.selection.set([]);
          this.load();
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected create(): void {
    void this.router.navigate(['/suppliers', 'new']);
  }

  protected accessLabel(supplier: Supplier): string {
    return supplier.storeAccessMode === 'all'
      ? 'suppliers.access.all'
      : 'suppliers.access.whitelist';
  }

  protected resetFilters(): void {
    this.search.set('');
    this.statusFilter.set('');
    this.applyFilters();
  }
}
