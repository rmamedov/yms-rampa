import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { PageSize, SyncRun } from '../../core/models';
import { SyncApi } from '../../core/data/sync.api';
import { AuditApi } from '../../core/data/audit.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { PaginationComponent } from '../../shared/ui/pagination.component';
import { EmptyStateComponent } from '../../shared/ui/empty-state.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import { formatDateTime, formatDuration } from '../../core/utils/time.util';

/** Розділ «Синхронізація MCP» (5.6): журнал, ручний запуск, diff. */
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
  private readonly auditApi = inject(AuditApi);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly runs = signal<readonly SyncRun[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageSize = signal<PageSize>(20);
  protected readonly busy = signal(false);
  protected readonly selected = signal<SyncRun | null>(null);

  protected readonly formatDateTime = formatDateTime;
  protected readonly formatDuration = formatDuration;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.api
      .list({ page: this.page(), pageSize: this.pageSize(), direction: 'desc' })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (page) => {
          this.runs.set(page.items);
          this.total.set(page.total);
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

  /** SYNC-02: ручний запуск доступний super_admin і network_manager. */
  protected run(): void {
    if (this.busy()) {
      this.toast.errorKey('sync.running');
      return;
    }
    this.busy.set(true);
    const initiator = this.auth.user()?.fullName ?? '';
    this.api
      .run(initiator)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (run) => {
          this.busy.set(false);
          this.toast.success('sync.started');
          this.auditApi
            .write({
              objectType: 'sync',
              objectId: run.id,
              objectLabel: run.id,
              action: 'sync_run',
              changes: [],
            })
            .pipe(takeUntilDestroyed(this.destroyRef))
            .subscribe({ error: () => undefined });
          this.page.set(1);
          this.load();
        },
        error: (error: unknown) => {
          this.busy.set(false);
          this.toast.error(error);
        },
      });
  }

  protected statusClass(run: SyncRun): string {
    switch (run.status) {
      case 'success':
        return 'badge badge-success';
      case 'error':
        return 'badge badge-danger';
      default:
        return 'badge badge-info';
    }
  }

  protected canRun(): boolean {
    return this.auth.can('store.sync.manage');
  }
}
