import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { PageSize, StaffRole, StaffUser, StoreListRow } from '../../core/models';
import { StaffApi } from '../../core/data/staff.api';
import { StoresApi } from '../../core/data/stores.api';
import { AuditApi } from '../../core/data/audit.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import {
  MultiSelectComponent,
  SelectOption,
} from '../../shared/ui/multi-select.component';
import {
  ROLE_ASSIGNMENT_TREE,
  ROLES_REQUIRING_STORES,
  STAFF_ROLES,
} from '../../core/rbac/permissions';
import { validateEmail } from '../../core/utils/validators.util';
import { DEFAULT_STORE_FILTER } from '../../core/utils/query-state.util';

/** Розділ «Користувачі staff» (5.5) з деревом призначення ролей (RBAC-22). */
@Component({
  selector: 'app-staff-users-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    PaginationComponent,
    EmptyStateComponent,
    ModalComponent,
    MultiSelectComponent,
  ],
  templateUrl: './staff-users.page.html',
})
export class StaffUsersPage {
  private readonly api = inject(StaffApi);
  private readonly storesApi = inject(StoresApi);
  private readonly auditApi = inject(AuditApi);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly rows = signal<readonly StaffUser[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);
  protected readonly search = signal('');
  protected readonly roleFilter = signal<StaffRole | ''>('');
  protected readonly storeOptions = signal<readonly SelectOption[]>([]);

  protected readonly dialogOpen = signal(false);
  protected readonly editingId = signal<string | null>(null);
  protected readonly fullName = signal('');
  protected readonly email = signal('');
  protected readonly phone = signal('+380');
  protected readonly role = signal<StaffRole>('store_manager');
  protected readonly storeIds = signal<readonly string[]>([]);
  protected readonly active = signal(true);
  protected readonly formError = signal<string | null>(null);

  protected readonly allRoles = STAFF_ROLES;

  /** RBAC-22: список ролей, які поточний актор може призначати. */
  protected readonly assignableRoles = computed<readonly StaffRole[]>(() => {
    const actorRole = this.auth.role();
    if (!actorRole) {
      return [];
    }
    return ROLE_ASSIGNMENT_TREE[actorRole] ?? [];
  });

  protected readonly requiresStores = computed(() =>
    (ROLES_REQUIRING_STORES as readonly string[]).includes(this.role()),
  );

  protected readonly emailError = computed(() => validateEmail(this.email()));
  protected readonly storesError = computed(() =>
    this.requiresStores() && this.storeIds().length === 0
      ? 'staff.error.stores'
      : null,
  );
  protected readonly invalid = computed(
    () =>
      this.fullName().trim() === '' ||
      this.emailError() !== null ||
      this.storesError() !== null,
  );

  constructor() {
    this.load();
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
  }

  protected load(): void {
    this.api
      .list(
        {
          search: this.search().trim(),
          roles: this.roleFilter() === '' ? [] : [this.roleFilter() as StaffRole],
          active: null,
        },
        { page: this.page(), pageSize: this.pageSize(), sort: 'fullName' },
      )
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

  protected setRoleFilter(event: Event): void {
    this.roleFilter.set((event.target as HTMLSelectElement).value as StaffRole | '');
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

  protected openCreate(): void {
    this.editingId.set(null);
    this.fullName.set('');
    this.email.set('');
    this.phone.set('+380');
    this.role.set(this.assignableRoles()[0] ?? 'store_manager');
    this.storeIds.set([]);
    this.active.set(true);
    this.formError.set(null);
    this.dialogOpen.set(true);
  }

  protected openEdit(user: StaffUser): void {
    this.editingId.set(user.id);
    this.fullName.set(user.fullName);
    this.email.set(user.email);
    this.phone.set(user.phone);
    this.role.set(user.role);
    this.storeIds.set(user.storeIds);
    this.active.set(user.active);
    this.formError.set(null);
    this.dialogOpen.set(true);
  }

  protected setRole(event: Event): void {
    this.role.set((event.target as HTMLSelectElement).value as StaffRole);
  }

  protected save(): void {
    if (this.invalid()) {
      return;
    }
    const actorId = this.auth.user()?.id ?? '';
    const editingId = this.editingId();
    const before = editingId
      ? (this.rows().find((r) => r.id === editingId) ?? null)
      : null;
    this.api
      .save(
        {
          id: editingId ?? undefined,
          fullName: this.fullName().trim(),
          email: this.email().trim(),
          phone: this.phone().trim(),
          role: this.role(),
          storeIds: this.requiresStores() ? this.storeIds() : [],
          active: this.active(),
        },
        actorId,
      )
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.dialogOpen.set(false);
          this.toast.success('conflicts.saved');
          // USR-04: зміна ролі та привʼязок фіксується зі старим і новим значеннями
          this.auditApi
            .write({
              objectType: 'staff_user',
              objectId: saved.id,
              objectLabel: saved.fullName,
              action: editingId ? 'update' : 'create',
              changes: before
                ? [
                    { field: 'role', oldValue: before.role, newValue: saved.role },
                    {
                      field: 'storeIds',
                      oldValue: before.storeIds.join(','),
                      newValue: saved.storeIds.join(','),
                    },
                  ]
                : [],
            })
            .pipe(takeUntilDestroyed(this.destroyRef))
            .subscribe({ error: () => undefined });
          this.load();
        },
        error: (error: unknown) => {
          const apiError = this.toast.error(error);
          this.formError.set(apiError.problem.detail ?? null);
        },
      });
  }

  /** USR-03: деактивація припиняє сесії; себе деактивувати не можна. */
  protected toggleActive(user: StaffUser): void {
    const actorId = this.auth.user()?.id ?? '';
    this.api
      .setActive(user.id, !user.active, actorId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.toast.success(saved.active ? 'staff.activate' : 'staff.deactivated');
          this.auditApi
            .write({
              objectType: 'staff_user',
              objectId: saved.id,
              objectLabel: saved.fullName,
              action: 'status_change',
              changes: [
                {
                  field: 'active',
                  oldValue: String(user.active),
                  newValue: String(saved.active),
                },
              ],
            })
            .pipe(takeUntilDestroyed(this.destroyRef))
            .subscribe({ error: () => undefined });
          this.load();
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected storeLabels(user: StaffUser): string {
    if (user.storeIds.length === 0) {
      return '—';
    }
    return user.storeIds
      .map((id) => this.storeOptions().find((o) => o.value === id)?.label ?? id)
      .join('; ');
  }

  protected isSelf(user: StaffUser): boolean {
    return user.id === this.auth.user()?.id;
  }
}
