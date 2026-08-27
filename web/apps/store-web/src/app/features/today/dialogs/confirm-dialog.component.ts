import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';
import { ModalComponent } from '../../../shared/modal.component';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';

/** Універсальне підтвердження (STW-15: no_show вимагає підтвердження). */
@Component({
  selector: 'app-confirm-dialog',
  standalone: true,
  imports: [ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-modal [titleKey]="titleKey()" (closed)="closed.emit()">
      <p>{{ textKey() | t: textParams() }}</p>
      <div class="modal__actions">
        <button type="button" class="btn" (click)="closed.emit()">
          {{ 'common.cancel' | t }}
        </button>
        <button type="button" class="btn btn--danger" (click)="confirmed.emit()">
          {{ 'common.confirm' | t }}
        </button>
      </div>
    </app-modal>
  `,
})
export class ConfirmDialogComponent {
  readonly titleKey = input.required<string>();
  readonly textKey = input.required<string>();
  readonly textParams = input<Record<string, string | number>>({});
  readonly confirmed = output<void>();
  readonly closed = output<void>();
}
