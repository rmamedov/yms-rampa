import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { ToastService } from '../../core/ui/toast.service';

@Component({
  selector: 'app-toast-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="toast-host">
      @for (toast of toasts.toasts(); track toast.id) {
        <div class="toast" [class]="'toast-' + toast.kind">
          <span>{{ toast.text }}</span>
          <button type="button" class="toast-close" (click)="toasts.dismiss(toast.id)">
            ✕
          </button>
        </div>
      }
    </div>
  `,
})
export class ToastHostComponent {
  protected readonly toasts = inject(ToastService);
}
