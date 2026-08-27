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
  StaffRole,
  StaffUser,
  StaffUserStatusFilter,
} from '../../core/models';
import { StaffUserFilter, UsersApi } from '../../core/data/users.api';
import { ToastService } from '../../core/ui/toast.service';
import { AuthService } from '../../core/auth/auth.service';
import { assignableRoles } from '../../core/rbac/permissions';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';

/**
 * Список облікових записів співробітників (розділ 4.7).
 *
 * Фільтри й пагінація — серверні: бекенд приймає ?q&role&status&page&perPage
 * і сам звужує вибірку до ролей, доступних акторові (RBAC-23), тому
 * «порожньо» тут може означати і «немає прав бачити», і «немає записів» —
 * пояснювальний текст приходить із бекенду разом із відповіддю.
 */
@Component({
  selector: 'app-user-list-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, PaginationComponent, EmptyStateComponent],
  templateUrl: './user-list.page.html',
})
export class UserListPage {
  private readonly api = inject(UsersApi);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly rows = signal<readonly StaffUser[]>([]);
  protected readonly total = signal(0);
  protected readonly emptyMessage = signal<string | null>(null);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);
  protected readonly search = signal('');
  protected readonly roleFilter = signal<StaffRole | ''>('');
  protected readonly statusFilter = signal<StaffUserStatusFilter>('');

  /** RBAC-23: у фільтрі лише ролі, якими актор має право керувати. */
  protected readonly roleOptions = computed(() => assignableRoles(this.auth.role()));

  constructor() {
    this.load();
  }

  private filter(): StaffUserFilter {
    return {
      search: this.search().trim(),
      role: this.roleFilter(),
      status: this.statusFilter(),
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
          this.emptyMessage.set(page.emptyMessage ?? null);
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

  protected setStatusFilter(event: Event): void {
    this.statusFilter.set(
      (event.target as HTMLSelectElement).value as StaffUserStatusFilter,
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

  protected resetFilters(): void {
    this.search.set('');
    this.roleFilter.set('');
    this.statusFilter.set('');
    this.applyFilters();
  }

  protected create(): void {
    void this.router.navigate(['/users', 'new']);
  }

  /**
   * RBAC-13: для магазинних ролей показуємо кількість магазинів, для
   * мережевих — «вся мережа», а нульовий доступ — окремим попередженням.
   */
  protected scopeLabel(user: StaffUser): string {
    if (user.scope.networkWide) {
      return 'users.scope.network';
    }
    return user.scope.zeroAccess ? 'users.scope.zero' : 'users.scope.stores';
  }
}
