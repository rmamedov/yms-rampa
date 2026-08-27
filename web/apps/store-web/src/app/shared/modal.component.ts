import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';
import { TranslatePipe } from '../core/i18n/translate.pipe';

/** Просте модальне вікно без сторонніх бібліотек. */
@Component({
  selector: 'app-modal',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="modal-backdrop"
      role="presentation"
      (click)="onBackdrop($event)"
      (keydown.escape)="closed.emit()"
    >
      <div
        class="modal"
        role="dialog"
        aria-modal="true"
        [attr.aria-label]="titleKey() | t"
      >
        <h2 class="modal__title">{{ titleKey() | t: titleParams() }}</h2>
        <ng-content />
      </div>
    </div>
  `,
})
export class ModalComponent {
  readonly titleKey = input.required<string>();
  readonly titleParams = input<Record<string, string | number>>({});
  readonly closed = output<void>();

  onBackdrop(event: MouseEvent): void {
    if (event.target === event.currentTarget) {
      this.closed.emit();
    }
  }
}
