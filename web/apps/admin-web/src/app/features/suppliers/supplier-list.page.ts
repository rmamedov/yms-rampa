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
  SupplierContact,
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

  /** AdminSupplierController приймає один статус, а не перелік. */
  private filter(): SupplierFilter {
    return {
      search: this.search().trim(),
      status: this.statusFilter() === '' ? null : (this.statusFilter() as SupplierStatus),
    };
  }

  protected load(): void {
    this.api
      .list(this.filter(), { page: this.page(), pageSize: this.pageSize() })
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
    return supplier.storeAccess.allStores
      ? 'suppliers.access.all'
      : 'suppliers.access.whitelist';
  }

  protected primaryContact(supplier: Supplier): SupplierContact {
    return supplier.contacts[0] ?? { name: '—', phone: null, email: null };
  }

  protected resetFilters(): void {
    this.search.set('');
    this.statusFilter.set('');
    this.applyFilters();
  }
}
