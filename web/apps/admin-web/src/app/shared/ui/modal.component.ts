import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';
import { TranslatePipe } from '../../core/i18n/translate.pipe';

@Component({
  selector: 'app-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <button
      type="button"
      class="modal-backdrop"
      [attr.aria-label]="title()"
      (click)="closed.emit()"
    ></button>
    <div class="modal" role="dialog" aria-modal="true">
      <div class="modal-header">
        <h3>{{ title() }}</h3>
        <button type="button" class="btn btn-link" (click)="closed.emit()">✕</button>
      </div>
      <div class="modal-body">
        <ng-content />
      </div>
      <div class="modal-footer">
        <ng-content select="[modalFooter]" />
        @if (showDefaultFooter()) {
          <button type="button" class="btn" (click)="closed.emit()">
            {{ 'common.cancel' | t }}
          </button>
        }
      </div>
    </div>
  `,
})
export class ModalComponent {
  readonly title = input.required<string>();
  readonly showDefaultFooter = input(false);
  readonly closed = output<void>();
}
