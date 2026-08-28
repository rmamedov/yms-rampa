import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  BranchChange,
  PageSize,
  SyncLogEntry,
  SyncReport,
  SyncRunDetails,
} from '../../core/models';
import { SyncApi } from '../../core/data/sync.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import { formatDateTime, formatSeconds } from '../../core/utils/time.util';

/**
 * Розділ «Синхронізація MCP» (5.6): журнал, ручний запуск і деталізація запуску.
 *
 * Рядок журналу відкриває звіт із поіменним переліком нових, змінених і
 * зниклих філій (GET /sync/log/{id}, SYNC-01). Перелік не тягнеться у список:
 * у таблиці потрібні лічильники, а не сотні рядків на кожен запуск.
 */
@Component({
  selector: 'app-sync-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    PaginationComponent,
    EmptyStateComponent,
    ModalComponent,
  ],
  templateUrl: './sync.page.html',
})
export class SyncPage {
  private readonly api = inject(SyncApi);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly runs = signal<readonly SyncLogEntry[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);
  protected readonly busy = signal(false);
  /** INT-13: банер «дані станом на …», коли остання синхронізація не успішна. */
  protected readonly lastSuccessfulAt = signal<string | null>(null);
  protected readonly running = signal(false);
  protected readonly lastReport = signal<SyncReport | null>(null);
  /** Обраний рядок журналу: деталізація конкретного запуску (SYNC-01). */
  protected readonly details = signal<SyncRunDetails | null>(null);

  protected readonly formatDateTime = formatDateTime;
  protected readonly formatSeconds = formatSeconds;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.api
      .log(this.page(), this.pageSize())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (log) => {
          this.runs.set(log.items);
          this.total.set(log.total);
          this.lastSuccessfulAt.set(log.lastSuccessfulAt);
          this.running.set(log.running);
        },
        error: (error: unknown) => this.toast.error(error),
      });
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
   * SYNC-02: ручний запуск доступний super_admin і network_manager.
   * Ініціатора підставляє бекенд із заголовків ідентичності — тіло порожнє.
   */
  protected run(): void {
    if (this.busy()) {
      this.toast.errorKey('sync.running');
      return;
    }
    this.busy.set(true);
    this.api
      .run()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (report) => {
          this.busy.set(false);
          this.lastReport.set(report);
          this.toast.success('sync.started');
          this.page.set(1);
          this.load();
        },
        error: (error: unknown) => {
          this.busy.set(false);
          this.toast.error(error);
        },
      });
  }

  /** Клік по рядку журналу — деталізація саме цього запуску. */
  protected openDetails(entry: SyncLogEntry): void {
    this.api
      .runDetails(entry.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (details) => this.details.set(details),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** Стисле «поле: було → стало» для рядка деталізації. */
  protected fieldSummary(change: BranchChange): string {
    return Object.entries(change.fields)
      .map(([name, value]) => `${name}: ${format(value.old)} → ${format(value.new)}`)
      .join('; ');
  }

  protected statusClass(entry: SyncLogEntry): string {
    switch (entry.status) {
      case 'success':
        return 'badge badge-success';
      case 'failed':
        return 'badge badge-danger';
      case 'partial':
        return 'badge badge-warn';
      default:
        return 'badge badge-info';
    }
  }

  protected canRun(): boolean {
    return this.auth.can('store.sync.manage');
  }
}

/** Значення поля MCP у переліку змін; порожнє показуємо як «—». */
function format(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '—';
  }
  return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
