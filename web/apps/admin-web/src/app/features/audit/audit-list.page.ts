import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AuditAction, AuditEntry, AuditLog, PageSize } from '../../core/models';
import { AuditApi, AuditFilter, DEFAULT_AUDIT_FILTER } from '../../core/data/audit.api';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { formatDateTime } from '../../core/utils/time.util';

/**
 * Журнал аудиту (RBAC-29, RBAC-31).
 *
 * ОБСЯГ: журнал показує зміни облікових записів і ролей — рівно те, що веде
 * `role_audit` в identity-staff-service. Дії над магазинами, постачальниками
 * й бронюваннями ведуть інші сервіси у власних журналах, спільного маршруту
 * для них немає; сторінка про це чесно попереджає, а не вдає повне покриття.
 */
@Component({
  selector: 'app-audit-list-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, PaginationComponent, EmptyStateComponent],
  templateUrl: './audit-list.page.html',
})
export class AuditListPage {
  private readonly api = inject(AuditApi);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly rows = signal<readonly AuditEntry[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);
  protected readonly actionFilter = signal<AuditAction | ''>('');
  /** Перелік дій для фільтра приходить із бекенду разом зі сторінкою. */
  protected readonly actionOptions = signal<AuditLog['actions']>([]);

  protected readonly formatDateTime = formatDateTime;

  constructor() {
    this.load();
  }

  private filter(): AuditFilter {
    return { ...DEFAULT_AUDIT_FILTER, action: this.actionFilter() };
  }

  protected load(): void {
    this.api
      .list(this.filter(), this.page(), this.pageSize())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (log) => {
          this.rows.set(log.items);
          this.total.set(log.total);
          if (log.actions.length > 0) {
            this.actionOptions.set(log.actions);
          }
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected setAction(event: Event): void {
    this.actionFilter.set((event.target as HTMLSelectElement).value as AuditAction | '');
    this.page.set(1);
    this.load();
  }

  protected resetFilters(): void {
    this.actionFilter.set('');
    this.page.set(1);
    this.load();
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

  /**
   * Що саме змінилося: порівнюємо знімки «до» і «після» і показуємо лише
   * відмінні поля. Повний JSON у таблиці нечитабельний, а самих лічильників
   * недосить, щоб зрозуміти зміну.
   */
  protected changeSummary(entry: AuditEntry): string {
    const keys = [...new Set([...Object.keys(entry.before), ...Object.keys(entry.after)])];
    const changed = keys
      .filter((key) => stringify(entry.before[key]) !== stringify(entry.after[key]))
      .map((key) => `${key}: ${stringify(entry.before[key])} → ${stringify(entry.after[key])}`);

    return changed.join('; ');
  }
}

function stringify(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '—';
  }
  if (Array.isArray(value)) {
    return value.length === 0 ? '—' : `${value.length}`;
  }
  return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
