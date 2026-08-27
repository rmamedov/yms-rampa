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
  StaffRole,
  StaffUser,
  StaffUserCredentials,
  StoreListRow,
} from '../../core/models';
import { StaffUserPatch, UsersApi } from '../../core/data/users.api';
import { StoresApi } from '../../core/data/stores.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { I18nService } from '../../core/i18n/i18n.service';
import { assignableRoles, ROLES_REQUIRING_STORES } from '../../core/rbac/permissions';
import {
  BreadcrumbsComponent,
  Crumb,
} from '../../shared/ui/breadcrumbs.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import {
  MultiSelectComponent,
  SelectOption,
} from '../../shared/ui/multi-select.component';
import { validateEmail } from '../../core/utils/validators.util';
import { DEFAULT_STORE_FILTER } from '../../core/utils/query-state.util';

/**
 * Картка облікового запису співробітника (розділ 4.7): створення і
 * редагування, деактивація, скидання пароля.
 *
 * Пароля тут немає як поля: його генерує бекенд і показує РІВНО ОДИН РАЗ
 * у модалці (AUTH-61) — так само, як для водіїв у кабінеті постачальника.
 */
@Component({
  selector: 'app-user-detail-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    BreadcrumbsComponent,
    ModalComponent,
    MultiSelectComponent,
  ],
  templateUrl: './user-detail.page.html',
})
export class UserDetailPage {
  private readonly api = inject(UsersApi);
  private readonly storesApi = inject(StoresApi);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly isNew = signal(false);
  protected readonly user = signal<StaffUser | null>(null);

  protected readonly email = signal('');
  protected readonly fullName = signal('');
  protected readonly role = signal<StaffRole | ''>('');
  protected readonly storeIds = signal<readonly string[]>([]);
  protected readonly storeOptions = signal<readonly SelectOption[]>([]);
  protected readonly storesLoaded = signal(false);

  /** Одноразовий показ пароля: після закриття модалки він зникає назавжди. */
  protected readonly credentials = signal<StaffUserCredentials | null>(null);
  protected readonly deactivateOpen = signal(false);
  protected readonly resetOpen = signal(false);

  /** RBAC-23: пропонуємо лише ролі з дерева призначення актора. */
  protected readonly roleOptions = computed(() => assignableRoles(this.auth.role()));

  /** RBAC-13: магазини має сенс привʼязувати лише магазинним ролям. */
  protected readonly storeScoped = computed(() =>
    (ROLES_REQUIRING_STORES as readonly string[]).includes(this.role()),
  );

  /** RBAC-13: порожній перелік для магазинної ролі = нуль доступу. */
  protected readonly zeroAccess = computed(
    () => this.storeScoped() && this.storeIds().length === 0,
  );

  /** RBAC-24: власну роль і власний скоуп змінювати не можна. */
  protected readonly isSelf = computed(
    () => !this.isNew() && this.user()?.id === this.auth.user()?.id,
  );

  protected readonly emailError = computed(() =>
    this.isNew() ? validateEmail(this.email(), 'users.error.email') : null,
  );
  protected readonly fullNameError = computed(() =>
    this.fullName().trim().length === 0 ? 'users.error.fullName' : null,
  );
  protected readonly roleError = computed(() =>
    this.role() === '' ? 'users.error.role' : null,
  );
  protected readonly invalid = computed(
    () =>
      this.emailError() !== null ||
      this.fullNameError() !== null ||
      this.roleError() !== null,
  );

  protected readonly crumbs = computed<readonly Crumb[]>(() => [
    { label: this.i18n.t('users.title'), link: ['/users'] },
    { label: this.user()?.fullName ?? this.i18n.t('users.add') },
  ]);

  constructor() {
    this.route.paramMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((params) => {
        const id = params.get('id');
        if (!id || id === 'new') {
          this.isNew.set(true);
          this.user.set(null);
          // Роль НЕ підставляється за замовчуванням: перший пункт дерева
          // super_admin — надто небезпечне «значення за замовчуванням».
          this.role.set('');
          return;
        }
        this.isNew.set(false);
        this.loadUser(id);
      });

    this.loadAllStoreOptions();
  }

  /**
   * Довідник філій для привʼязки — ПОВНИЙ.
   *
   * Одна сторінка на 100 записів із 455 давала б неповний список: пошук у
   * мультиселекті працює по вже завантаженому масиву, тому філії з «хвоста»
   * просто неможливо було б знайти. Той самий фікс, що й у картці
   * постачальника (supplier-detail.page.ts).
   *
   * Записи без міста або адреси не показуємо: це сміття з MCP, яке однаково
   * неможливо активувати.
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

            const more = collected.length < result.total && result.items.length > 0;
            if (more) {
              fetchPage(page + 1);
              return;
            }

            this.storeOptions.set(
              collected
                .filter((row) => row.city?.trim() && row.address?.trim())
                .map((row) => ({
                  value: row.id,
                  label: `${row.externalId} — ${row.city}, ${row.address}`,
                })),
            );
            this.storesLoaded.set(true);
          },
          // Часткова вибірка гірша за явну порожнечу: інакше адміністратор
          // не помітить, що бачить лише частину мережі.
          error: () => {
            this.storeOptions.set([]);
            this.storesLoaded.set(true);
          },
        });
    };

    fetchPage(1);
  }

  private loadUser(id: string): void {
    this.api
      .get(id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (user) => this.apply(user),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  private apply(user: StaffUser): void {
    this.user.set(user);
    this.email.set(user.email);
    this.fullName.set(user.fullName);
    this.role.set(user.role);
    this.storeIds.set(user.scope.storeIds);
  }

  protected setRole(event: Event): void {
    this.role.set((event.target as HTMLSelectElement).value as StaffRole | '');
  }

  protected storeLabel(id: string): string {
    return this.storeOptions().find((o) => o.value === id)?.label ?? id;
  }

  protected save(): void {
    if (this.invalid()) {
      return;
    }
    const role = this.role() as StaffRole;
    const current = this.user();

    if (!current) {
      this.api
        .create({
          email: this.email().trim(),
          fullName: this.fullName().trim(),
          role,
          storeIds: this.storeScoped() ? this.storeIds() : [],
          // Пароль генерує бекенд — адміністратор його не вигадує.
          password: null,
        })
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (credentials) => {
            this.apply(credentials.user);
            this.isNew.set(false);
            this.credentials.set(credentials);
            this.toast.success('users.created');
            void this.router.navigate(['/users', credentials.user.id]);
          },
          error: (error: unknown) => this.toast.error(error),
        });
      return;
    }

    // PATCH застосовує лише змінені поля: роль і скоуп мають окремі
    // інваріанти на бекенді (RBAC-24), і зайвий раз їх чіпати не варто.
    const patch: StaffUserPatch = {
      ...(this.fullName().trim() === current.fullName
        ? {}
        : { fullName: this.fullName().trim() }),
      ...(role === current.role || this.isSelf() ? {} : { role }),
      ...(this.isSelf() ? {} : { storeIds: this.storeScoped() ? this.storeIds() : [] }),
    };

    if (Object.keys(patch).length === 0) {
      this.toast.info('users.nothingToSave');
      return;
    }

    this.api
      .update(current.id, patch)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.apply(saved);
          this.toast.success('users.saved');
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** AUTH-12/RBAC-26: вхід блокується, активні сесії гасяться негайно. */
  protected confirmDeactivate(): void {
    const current = this.user();
    if (!current) {
      return;
    }
    this.api
      .deactivate(current.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.apply(saved);
          this.deactivateOpen.set(false);
          this.toast.success('users.deactivated');
        },
        error: (error: unknown) => {
          this.deactivateOpen.set(false);
          this.toast.error(error);
        },
      });
  }

  protected activate(): void {
    const current = this.user();
    if (!current) {
      return;
    }
    this.api
      .activate(current.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (saved) => {
          this.apply(saved);
          this.toast.success('users.activated');
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** Перегенерація пароля: старий інвалідовується, новий показується раз. */
  protected confirmReset(): void {
    const current = this.user();
    if (!current) {
      return;
    }
    this.api
      .resetPassword(current.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (credentials) => {
          this.apply(credentials.user);
          this.resetOpen.set(false);
          this.credentials.set(credentials);
        },
        error: (error: unknown) => {
          this.resetOpen.set(false);
          this.toast.error(error);
        },
      });
  }

  protected copyPassword(): void {
    const password = this.credentials()?.password;
    if (!password) {
      return;
    }
    void navigator.clipboard
      ?.writeText(password)
      .then(() => this.toast.success('common.copied'))
      .catch(() => this.toast.errorKey('users.copyFailed'));
  }

  protected closeCredentials(): void {
    this.credentials.set(null);
  }
}
