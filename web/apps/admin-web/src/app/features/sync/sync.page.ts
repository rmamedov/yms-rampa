import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { PageSize, SyncLogEntry, SyncReport } from '../../core/models';
import { SyncApi } from '../../core/data/sync.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import { formatDateTime, formatSeconds } from '../../core/utils/time.util';

/**
 * Розділ «Синхронізація MCP» (5.6): журнал і ручний запуск.
 *
 * Порядкового diff (створені/змінені/відсутні філії) бекенд не віддає —
 * SyncLogEntry несе лише лічильники, тож деталізація показує звіт запуску.
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
