import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
  signal,
} from '@angular/core';
import {
  ConfigConflict,
  ConflictDecision,
  ConflictResolution,
} from '../../core/models';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { ModalComponent } from '../../shared/ui/modal.component';
import { canSaveConfig, unresolvedCount } from '../../core/utils/conflicts.util';
import { formatDate } from '../../core/utils/time.util';
import { copyToClipboard } from '../../core/utils/download.util';

/** STC-62…STC-64: список конфліктів і обовʼязкове рішення по кожному. */
@Component({
  selector: 'app-conflicts-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, ModalComponent],
  templateUrl: './conflicts-dialog.component.html',
})
export class ConflictsDialogComponent {
  readonly conflicts = input.required<readonly ConfigConflict[]>();
  readonly effectiveFrom = input.required<string>();
  readonly confirmed = output<readonly ConflictDecision[]>();
  readonly closed = output<void>();

  protected readonly decisions = signal<readonly ConflictDecision[]>([]);
  protected readonly formatDate = formatDate;
  protected readonly copied = signal<string | null>(null);

  protected readonly unresolved = computed(() =>
    unresolvedCount(this.conflicts(), this.decisions()),
  );
  protected readonly canSave = computed(() =>
    canSaveConfig(this.conflicts(), this.decisions()),
  );

  protected resolutionOf(conflictId: string): ConflictResolution | null {
    return this.decisions().find((d) => d.conflictId === conflictId)?.resolution ?? null;
  }

  protected decide(conflictId: string, resolution: ConflictResolution): void {
    this.decisions.update((list) => [
      ...list.filter((d) => d.conflictId !== conflictId),
      { conflictId, resolution },
    ]);
  }

  /** UI-02: пакетне «Скасувати з нотифікацією». */
  protected cancelAll(): void {
    this.decisions.set(
      this.conflicts().map((c) => ({
        conflictId: c.id,
        resolution: 'cancel_notify' as ConflictResolution,
      })),
    );
  }

  protected copyPhone(phone: string): void {
    void copyToClipboard(phone).then((ok) => {
      if (ok) {
        this.copied.set(phone);
      }
    });
  }

  protected confirm(): void {
    if (!this.canSave()) {
      return;
    }
    this.confirmed.emit(this.decisions());
  }
}
