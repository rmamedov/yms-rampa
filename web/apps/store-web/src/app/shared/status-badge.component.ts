import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { BookingStatus } from '../core/models/booking.model';
import { statusTone } from '../core/util/status.util';
import { TranslatePipe } from '../core/i18n/translate.pipe';

/** Статус кольором + обовʼязково текстом (STW-08, доступність). */
@Component({
  selector: 'app-status-badge',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="badge" [class]="'badge badge--' + tone()">{{
    'status.' + status() | t
  }}</span>`,
})
export class StatusBadgeComponent {
  readonly status = input.required<BookingStatus>();
  readonly tone = computed(() => statusTone(this.status()));
}
