import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { I18nService } from '../../core/i18n/i18n.service';
import type { BookingStatus } from '../../core/models/models';

@Component({
  selector: 'app-status-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="badge" [class]="'badge--' + status()">{{
    label()
  }}</span>`,
  styles: [
    `
      .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
        border: 1px solid transparent;
      }
      .badge--booked {
        background: #e0edff;
        color: #1d4ed8;
        border-color: #bfd7ff;
      }
      .badge--arrived {
        background: #e6f6ec;
        color: #15803d;
        border-color: #c3e9d0;
      }
      .badge--unloading {
        background: #fff4d6;
        color: #a16207;
        border-color: #f3e0a8;
      }
      .badge--completed {
        background: #eef2f7;
        color: #334155;
        border-color: #dbe3ec;
      }
      .badge--cancelled,
      .badge--no_show,
      .badge--rejected {
        background: #fdeaea;
        color: #b91c1c;
        border-color: #f5c9c9;
      }
    `,
  ],
})
export class StatusBadgeComponent {
  private readonly i18n = inject(I18nService);
  readonly status = input.required<BookingStatus>();
  protected readonly label = computed(() =>
    this.i18n.t(`status.${this.status()}`),
  );
}
