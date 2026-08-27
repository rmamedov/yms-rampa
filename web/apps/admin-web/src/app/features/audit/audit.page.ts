import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  AuditAction,
  AuditEntry,
  AuditFilter,
  AuditObjectType,
  PageSize,
} from '../../core/models';
import { AuditApi } from '../../core/data/audit.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { formatDateTime } from '../../core/utils/time.util';
import { buildCsv, csvFileName, CsvColumn } from '../../core/utils/csv.util';
import { downloadTextFile } from '../../core/utils/download.util';
import { I18nService } from '../../core/i18n/i18n.service';

const OBJECT_TYPES: readonly AuditObjectType[] = [
  'store',
  'supplier',
  'staff_user',
  'supplier_user',
  'sync',
  'analytics',
  'slot_block',
  'reserved_rule',
];

const ACTIONS: readonly AuditAction[] = [
  'create',
  'update',
  'delete',
  'status_change',
  'sync_run',
  'export',
  'conflict_resolve',
];

/** Аудит-лог адмін-дій (5.8): фільтри, серверна пагінація, експорт CSV. */
@Component({
  selector: 'app-audit-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, PaginationComponent, EmptyStateComponent],
  templateUrl: './audit.page.html',
})
export class AuditPage {
  private readonly api = inject(AuditApi);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly rows = signal<readonly AuditEntry[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);

  protected readonly userId = signal('');
  protected readonly objectType = signal<AuditObjectType | ''>('');
  protected readonly action = signal<AuditAction | ''>('');
  protected readonly from = signal('');
  protected readonly to = signal('');

  protected readonly objectTypes = OBJECT_TYPES;
  protected readonly actions = ACTIONS;
  protected readonly formatDateTime = formatDateTime;

  constructor() {
    this.load();
  }

  protected filter(): AuditFilter {
    return {
      userId: this.userId() === '' ? null : this.userId(),
      objectType: this.objectType() === '' ? null : (this.objectType() as AuditObjectType),
      action: this.action() === '' ? null : (this.action() as AuditAction),
      from: this.from() === '' ? null : this.from(),
      to: this.to() === '' ? null : this.to(),
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

  protected setObjectType(event: Event): void {
    this.objectType.set(
      (event.target as HTMLSelectElement).value as AuditObjectType | '',
    );
    this.applyFilters();
  }

  protected setAction(event: Event): void {
    this.action.set((event.target as HTMLSelectElement).value as AuditAction | '');
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

  protected changesLabel(entry: AuditEntry): string {
    if (entry.changes.length === 0) {
      return '—';
    }
    return entry.changes
      .map((c) => `${c.field}: ${c.oldValue ?? '—'} → ${c.newValue ?? '—'}`)
      .join('; ');
  }

  /** AUD-02: експорт CSV усієї вибірки, а не однієї сторінки. */
  protected exportCsv(): void {
    this.api
      .all(this.filter())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (entries) => {
          const columns: CsvColumn<AuditEntry>[] = [
            { header: 'Час (Europe/Kyiv)', value: (r) => formatDateTime(r.at) },
            { header: 'Користувач', value: (r) => r.userName },
            { header: 'Роль', value: (r) => this.i18n.t(`role.${r.role}`) },
            { header: 'IP', value: (r) => r.ip },
            {
              header: 'Тип обʼєкта',
              value: (r) => this.i18n.t(`audit.object.${r.objectType}`),
            },
            { header: 'Обʼєкт', value: (r) => r.objectLabel },
            { header: 'Дія', value: (r) => this.i18n.t(`audit.action.${r.action}`) },
            { header: 'Зміни', value: (r) => this.changesLabel(r) },
          ];
          const name = csvFileName('audit');
          downloadTextFile(buildCsv(entries, columns), name);
          this.toast.success('analytics.export.done', { name });
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }
}
