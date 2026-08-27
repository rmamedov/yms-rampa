import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { ToastService } from './toast.service';

@Component({
  selector: 'app-toast-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="toast-host" role="status" aria-live="polite">
      @for (toast of toasts.toasts(); track toast.id) {
        <div class="toast" [class]="'toast--' + toast.kind">
          <span class="toast__text">{{ toast.text }}</span>
          <button
            type="button"
            class="toast__close"
            (click)="toasts.dismiss(toast.id)"
            aria-label="Закрити"
          >
            ×
          </button>
        </div>
      }
    </div>
  `,
  styles: [
    `
      .toast-host {
        position: fixed;
        right: 16px;
        bottom: 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        z-index: 60;
        max-width: min(420px, calc(100vw - 32px));
      }
      .toast {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 10px;
        color: #fff;
        box-shadow: 0 10px 24px rgb(15 23 42 / 25%);
        font-size: 14px;
        line-height: 1.4;
      }
      .toast--success {
        background: #15803d;
      }
      .toast--error {
        background: #b91c1c;
      }
      .toast--info {
        background: #1f2937;
      }
      .toast__close {
        background: none;
        border: 0;
        color: inherit;
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
      }
    `,
  ],
})
export class ToastHostComponent {
  protected readonly toasts = inject(ToastService);
}
