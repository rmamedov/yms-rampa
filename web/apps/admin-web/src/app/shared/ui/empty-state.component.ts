import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';

/** STL-06 / ANL-13: пояснювальний порожній стан замість «нічого». */
@Component({
  selector: 'app-empty-state',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="empty-state">
      <p>{{ message() }}</p>
      @if (actionLabel()) {
        <button type="button" class="btn btn-sm" (click)="action.emit()">
          {{ actionLabel() }}
        </button>
      }
    </div>
  `,
})
export class EmptyStateComponent {
  readonly message = input.required<string>();
  readonly actionLabel = input<string | null>(null);
  readonly action = output<void>();
}
