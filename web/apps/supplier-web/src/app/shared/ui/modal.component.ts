import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';

@Component({
  selector: 'app-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="modal__backdrop"
      aria-label="Закрити"
      (click)="closed.emit()"
    ></button>
    <div
      class="modal__window"
      role="dialog"
      aria-modal="true"
      [attr.aria-label]="title()"
    >
      <header class="modal__head">
        <h2 class="modal__title">{{ title() }}</h2>
        <button
          type="button"
          class="modal__close"
          (click)="closed.emit()"
          aria-label="Закрити"
        >
          ×
        </button>
      </header>
      <div class="modal__body">
        <ng-content />
      </div>
      <footer class="modal__foot">
        <ng-content select="[modalFooter]" />
      </footer>
    </div>
  `,
  styles: [
    `
      :host {
        position: fixed;
        inset: 0;
        z-index: 50;
        display: grid;
        place-items: center;
        padding: 16px;
      }
      .modal__backdrop {
        position: absolute;
        inset: 0;
        border: 0;
        padding: 0;
        cursor: default;
        background: rgb(15 23 42 / 45%);
      }
      .modal__window {
        position: relative;
        width: min(560px, 100%);
        max-height: 90vh;
        overflow: auto;
        background: var(--surface, #fff);
        border-radius: 14px;
        box-shadow: 0 24px 60px rgb(15 23 42 / 25%);
      }
      .modal__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 20px 8px;
      }
      .modal__title {
        margin: 0;
        font-size: 18px;
        font-weight: 650;
      }
      .modal__close {
        border: 0;
        background: none;
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
        color: var(--text-muted, #64748b);
      }
      .modal__body {
        padding: 8px 20px 4px;
      }
      .modal__foot {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 16px 20px 20px;
      }
    `,
  ],
})
export class ModalComponent {
  readonly title = input<string>('');
  readonly closed = output<void>();
}
