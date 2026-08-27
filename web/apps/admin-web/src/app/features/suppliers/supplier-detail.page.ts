import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { StoreListRow, Supplier } from '../../core/models';
import { SuppliersApi } from '../../core/data/suppliers.api';
import { StoresApi } from '../../core/data/stores.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { I18nService } from '../../core/i18n/i18n.service';
import {
  BreadcrumbsComponent,
  Crumb,
} from '../../shared/ui/breadcrumbs.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import {
  MultiSelectComponent,
  SelectOption,
} from '../../shared/ui/multi-select.component';
import {
  validateEdrpou,
  validateEmail,
  validateRequiredPhone,
} from '../../core/utils/validators.util';
import { DEFAULT_STORE_FILTER } from '../../core/utils/query-state.util';

/**
 * Картка постачальника.
 *
 * Вкладок «Користувачі», «Автопарк» і «Водії» тут немає: у контурі
 * /api/admin/v1 бекенд таких маршрутів не надає — водії й ТЗ живуть
 * лише в кабінеті постачальника (/api/supplier/v1/drivers, /vehicles),
 * а користувачів створює internal-контур identity-partner-service.
 */
export type SupplierTabId = 'general' | 'stores';

const TABS: readonly SupplierTabId[] = ['general', 'stores'];

@Component({
  selector: 'app-supplier-detail-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    BreadcrumbsComponent,
    ModalComponent,
    MultiSelectComponent,
  ],
  templateUrl: './supplier-detail.page.html',
})
export class SupplierDetailPage {
  private readonly api = inject(SuppliersApi);
  private readonly storesApi = inject(StoresApi);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly tabs = TABS;
  protected readonly activeTab = signal<SupplierTabId>('general');
  protected readonly isNew = signal(false);
  protected readonly supplier = signal<Supplier | null>(null);

  protected readonly name = signal('');
  protected readonly edrpou = signal('');
  protected readonly contactName = signal('');
  protected readonly contactPhone = signal('+380');
  protected readonly contactEmail = signal('');
  protected readonly allStores = signal(true);
  protected readonly allowedStoreIds = signal<readonly string[]>([]);
  protected readonly storeOptions = signal<readonly SelectOption[]>([]);

  protected readonly suspendOpen = signal(false);
  protected readonly suspendReason = signal('');

  protected readonly canManage = computed(() => this.auth.can('supplier.manage'));

  protected readonly nameError = computed(() =>
    this.name().trim().length === 0 ? 'suppliers.error.name' : null,
  );
  /** edrpou у бекенді nullable — валідуємо лише заповнене значення. */
  protected readonly edrpouError = computed(() =>
    this.edrpou().trim() === '' ? null : validateEdrpou(this.edrpou()),
  );
  protected readonly phoneError = computed(() =>
    this.contactPhone().trim() === '' || this.contactPhone().trim() === '+380'
      ? null
      : validateRequiredPhone(this.contactPhone()),
  );
  protected readonly emailError = computed(() =>
    this.contactEmail().trim() === ''
      ? null
      : validateEmail(this.contactEmail(), 'suppliers.error.email'),
  );
  protected readonly contactNameError = computed(() =>
    this.contactName().trim() === '' ? 'suppliers.error.contactName' : null,
  );
  protected readonly invalid = computed(
    () =>
      this.nameError() !== null ||
      this.edrpouError() !== null ||
      this.phoneError() !== null ||
      this.emailError() !== null ||
      this.contactNameError() !== null,
  );

  protected readonly crumbs = computed<readonly Crumb[]>(() => [
    { label: this.i18n.t('suppliers.title'), link: ['/suppliers'] },
    { label: this.supplier()?.name ?? this.i18n.t('suppliers.add') },
  ]);

  constructor() {
    this.route.paramMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((params) => {
        const id = params.get('id');
        if (!id || id === 'new') {
          this.isNew.set(true);
          this.supplier.set(null);
          return;
        }
        this.isNew.set(false);
        this.loadSupplier(id);
      });

    this.storesApi
      .list(DEFAULT_STORE_FILTER, { page: 1, pageSize: 100, sort: 'city' })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (page) =>
          this.storeOptions.set(
            page.items.map((row: StoreListRow) => ({
              value: row.id,
              label: `${row.externalId} — ${row.city}, ${row.address}`,
            })),
          ),
        error: () => this.storeOptions.set([]),
      });
  }

  private loadSupplier(id: string): void {
    this.api
      .get(id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (supplier) => this.apply(supplier),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  private apply(supplier: Supplier): void {
    this.supplier.set(supplier);
    this.name.set(supplier.name);
    this.edrpou.set(supplier.edrpou ?? '');
    const contact = supplier.contacts[0];
    this.contactName.set(contact?.name ?? '');
    this.contactPhone.set(contact?.phone ?? '');
    this.contactEmail.set(contact?.email ?? '');
    this.allStores.set(supplier.storeAccess.allStores);
    this.allowedStoreIds.set(supplier.storeAccess.storeIds);
  }

  protected selectTab(tab: SupplierTabId): void {
    this.activeTab.set(tab);
  }

  protected setAccessMode(event: Event): void {
    this.allStores.set((event.target as HTMLSelectElement).value === 'all');
  }

  protected accessMode(): 'all' | 'whitelist' {
    return this.allStores() ? 'all' : 'whitelist';
  }

  protected save(): void {
    if (this.invalid()) {
      return;
    }
    const current = this.supplier();
    const draft = {
      name: this.name().trim(),
      edrpou: this.edrpou().trim() === '' ? null : this.edrpou().trim(),
      allStores: this.allStores(),
      storeIds: this.allStores() ? [] : this.allowedStoreIds(),
      contacts: [
        {
          name: this.contactName().trim(),
          phone: this.contactPhone().trim() === '' ? null : this.contactPhone().trim(),
          email: this.contactEmail().trim() === '' ? null : this.contactEmail().trim(),
        },
      ],
    };
    const request$ = current
      ? this.api.update(current.id, draft)
      : this.api.create(draft);

    request$.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (saved) => {
        this.apply(saved);
        this.isNew.set(false);
        this.toast.success('conflicts.saved');
        void this.router.navigate(['/suppliers', saved.id]);
      },
      error: (error: unknown) => this.toast.error(error),
    });
  }

  /** SUP-02: призупинення окремим маршрутом, з необовʼязковою причиною. */
  protected confirmSuspend(): void {
    const supplier = this.supplier();
    if (!supplier) {
      return;
    }
    const reason = this.suspendReason().trim();
    this.api
      .suspend(supplier.id, reason === '' ? null : reason)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.apply(saved);
          this.suspendOpen.set(false);
          this.suspendReason.set('');
          this.toast.success('conflicts.saved');
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected activate(): void {
    const supplier = this.supplier();
    if (!supplier) {
      return;
    }
    this.api
      .activate(supplier.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.apply(saved);
          this.toast.success('conflicts.saved');
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** SUP-06: видалення заборонене за наявності бронювань (409). */
  protected remove(): void {
    const supplier = this.supplier();
    if (!supplier) {
      return;
    }
    this.api
      .remove(supplier.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.toast.success('suppliers.deleted');
          void this.router.navigate(['/suppliers']);
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected storeLabel(id: string): string {
    return this.storeOptions().find((o) => o.value === id)?.label ?? id;
  }
}
