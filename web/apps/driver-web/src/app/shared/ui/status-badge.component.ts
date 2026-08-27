import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import type { BookingStatus } from '../../core/models/route-sheet.model';

/** Мапінг канонічного статусу бронювання на подання для водія (8.7). */
export const STATUS_TONE: Record<BookingStatus, string> = {
  booked: 'neutral',
  arrived: 'primary',
  unloading: 'primary',
  completed: 'success',
  cancelled: 'danger',
  no_show: 'danger',
};

@Component({
  selector: 'app-status-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `<span class="badge" [class]="tone()">{{
    'status.' + status() | t
  }}</span>`,
  styles: [
    `
      @use 'tokens' as *;
      .badge {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 0 $space-3;
        border-radius: 999px;
        font-size: $font-size-sm;
        font-weight: 600;
        white-space: nowrap;
      }
      .neutral {
        background: $color-neutral-soft;
        color: $color-neutral;
      }
      .primary {
        background: $color-primary-soft;
        color: $color-primary-dark;
      }
      .success {
        background: $color-success-soft;
        color: $color-success;
      }
      .danger {
        background: $color-danger-soft;
        color: $color-danger;
      }
      .warning {
        background: $color-warning-soft;
        color: $color-warning;
      }
    `,
  ],
})
export class StatusBadgeComponent {
  readonly status = input.required<BookingStatus>();
  readonly tone = computed(() => STATUS_TONE[this.status()]);
}
