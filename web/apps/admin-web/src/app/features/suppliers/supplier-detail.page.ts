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
import {
  StoreListRow,
  Supplier,
  SupplierDriver,
  SupplierStatus,
  SupplierUser,
  SupplierUserRole,
  Vehicle,
} from '../../core/models';
import { SuppliersApi } from '../../core/data/suppliers.api';
import { StoresApi } from '../../core/data/stores.api';
import { AuditApi } from '../../core/data/audit.api';
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

export type SupplierTabId = 'general' | 'stores' | 'users' | 'vehicles' | 'drivers';

const TABS: readonly SupplierTabId[] = [
  'general',
  'stores',
  'users',
  'vehicles',
  'drivers',
];

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
  private readonly auditApi = inject(AuditApi);
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
  protected readonly contactPerson = signal('');
  protected readonly contactPhone = signal('+380');
  protected readonly contactEmail = signal('');
  protected readonly status = signal<SupplierStatus>('active');
  protected readonly accessMode = signal<'all' | 'whitelist'>('all');
  protected readonly allowedStoreIds = signal<readonly string[]>([]);

  protected readonly users = signal<readonly SupplierUser[]>([]);
  protected readonly vehicles = signal<readonly Vehicle[]>([]);
  protected readonly drivers = signal<readonly SupplierDriver[]>([]);
  protected readonly vehicleSearch = signal('');
  protected readonly driverSearch = signal('');
  protected readonly storeOptions = signal<readonly SelectOption[]>([]);

  protected readonly userDialogOpen = signal(false);
  protected readonly userFullName = signal('');
  protected readonly userEmail = signal('');
  protected readonly userPhone = signal('+380');
  protected readonly userRole = signal<SupplierUserRole>('supplier_operator');
  protected readonly userError = signal<string | null>(null);

  protected readonly canManage = computed(() => this.auth.can('supplier.manage'));

  protected readonly nameError = computed(() =>
    this.name().trim().length === 0 ? 'suppliers.error.name' : null,
  );
  protected readonly edrpouError = computed(() => validateEdrpou(this.edrpou()));
  protected readonly phoneError = computed(() =>
    validateRequiredPhone(this.contactPhone()),
  );
  protected readonly emailError = computed(() =>
    validateEmail(this.contactEmail(), 'suppliers.error.email'),
  );
  protected readonly invalid = computed(
    () =>
      this.nameError() !== null ||
      this.edrpouError() !== null ||
      this.phoneError() !== null ||
      this.emailError() !== null,
  );

  protected readonly crumbs = computed<readonly Crumb[]>(() => [
    { label: this.i18n.t('suppliers.title'), link: ['/suppliers'] },
    {
      label: this.supplier()?.name ?? this.i18n.t('suppliers.add'),
    },
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
        next: (supplier) => {
          this.supplier.set(supplier);
          this.name.set(supplier.name);
          this.edrpou.set(supplier.edrpou);
          this.contactPerson.set(supplier.contactPerson);
          this.contactPhone.set(supplier.contactPhone);
          this.contactEmail.set(supplier.contactEmail);
          this.status.set(supplier.status);
          this.accessMode.set(supplier.storeAccessMode);
          this.allowedStoreIds.set(supplier.allowedStoreIds);
          this.loadRelated(supplier.id);
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  private loadRelated(supplierId: string): void {
    this.api
      .users(supplierId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ next: (users) => this.users.set(users), error: () => undefined });
    this.reloadVehicles();
    this.reloadDrivers();
  }

  protected reloadVehicles(): void {
    const supplier = this.supplier();
    if (!supplier) {
      return;
    }
    this.api
      .vehicles(supplier.id, this.vehicleSearch())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (vehicles) => this.vehicles.set(vehicles),
        error: () => undefined,
      });
  }

  protected reloadDrivers(): void {
    const supplier = this.supplier();
    if (!supplier) {
      return;
    }
    this.api
      .drivers(supplier.id, this.driverSearch())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (drivers) => this.drivers.set(drivers),
        error: () => undefined,
      });
  }

  protected selectTab(tab: SupplierTabId): void {
    this.activeTab.set(tab);
  }

  protected setStatus(event: Event): void {
    this.status.set((event.target as HTMLSelectElement).value as SupplierStatus);
  }

  protected setAccessMode(event: Event): void {
    this.accessMode.set(
      (event.target as HTMLSelectElement).value as 'all' | 'whitelist',
    );
  }

  protected save(): void {
    if (this.invalid()) {
      return;
    }
    const current = this.supplier();
    this.api
      .save({
        id: current?.id,
        name: this.name().trim(),
        edrpou: this.edrpou().trim(),
        contactPerson: this.contactPerson().trim(),
        contactPhone: this.contactPhone().trim(),
        contactEmail: this.contactEmail().trim(),
        status: this.status(),
        storeAccessMode: this.accessMode(),
        allowedStoreIds: this.accessMode() === 'whitelist' ? this.allowedStoreIds() : [],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.supplier.set(saved);
          this.isNew.set(false);
          this.toast.success('conflicts.saved');
          this.auditApi
            .write({
              objectType: 'supplier',
              objectId: saved.id,
              objectLabel: saved.name,
              action: current ? 'update' : 'create',
              changes: current
                ? [
                    {
                      field: 'status',
                      oldValue: current.status,
                      newValue: saved.status,
                    },
                  ]
                : [],
            })
            .pipe(takeUntilDestroyed(this.destroyRef))
            .subscribe({ error: () => undefined });
          void this.router.navigate(['/suppliers', saved.id]);
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** SUP-06: видалення заборонене за наявності бронювань. */
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
          this.toast.success('common.yes');
          void this.router.navigate(['/suppliers']);
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected openUserDialog(): void {
    this.userFullName.set('');
    this.userEmail.set('');
    this.userPhone.set('+380');
    this.userRole.set('supplier_operator');
    this.userError.set(null);
    this.userDialogOpen.set(true);
  }

  protected setUserRole(event: Event): void {
    this.userRole.set((event.target as HTMLSelectElement).value as SupplierUserRole);
  }

  protected saveUser(): void {
    const supplier = this.supplier();
    if (!supplier) {
      return;
    }
    const emailError = validateEmail(this.userEmail(), 'suppliers.error.email');
    const phoneError = validateRequiredPhone(this.userPhone());
    const error = emailError ?? phoneError;
    if (this.userFullName().trim() === '' || error) {
      this.userError.set(error ?? 'staff.error.fullName');
      return;
    }
    this.api
      .saveUser({
        supplierId: supplier.id,
        fullName: this.userFullName().trim(),
        email: this.userEmail().trim(),
        phone: this.userPhone().trim(),
        role: this.userRole(),
        active: true,
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.userDialogOpen.set(false);
          this.loadRelated(supplier.id);
          this.toast.success('conflicts.saved');
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** SUP-04: скидання пароля — тимчасовий пароль надсилає notification-service. */
  protected resetPassword(user: SupplierUser): void {
    this.api
      .resetUserPassword(user.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () =>
          this.toast.success('suppliers.user.passwordReset', { email: user.email }),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected storeLabel(id: string): string {
    return this.storeOptions().find((o) => o.value === id)?.label ?? id;
  }
}
