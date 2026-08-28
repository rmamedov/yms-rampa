import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core';
import { Booking, Ramp } from '../core/models';
import { DailyStats } from '../core/util/board.util';
import { I18nService } from '../core/i18n/i18n.service';
import { TranslatePipe } from '../core/i18n/translate.pipe';

interface SummaryItem {
  readonly key: string;
  readonly value: string;
  /** Друге значення поруч — частка, відсоток. Порожнє, якщо рахувати нема з чого. */
  readonly share: string;
  readonly icon: string;
}

/**
 * Нижня зведена: чотири числа про поточний стан двору.
 *
 * Усе рахується з тих самих бронювань, що вже на екрані — окремих запитів
 * немає, тож панель не може розійтися зі списком над нею.
 */
@Component({
  selector: 'app-summary-bar',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="summary" [attr.aria-label]="'summary.title' | t">
      @for (item of items(); track item.key) {
        <div class="summary__item">
          <span class="summary__icon" aria-hidden="true" [innerHTML]="item.icon"></span>
          <div class="summary__body">
            <span class="summary__label">{{ item.key | t }}</span>
            <div class="summary__value">
              <span>{{ item.value }}</span>
              @if (item.share) {
                <span class="summary__share">{{ item.share }}</span>
              }
            </div>
          </div>
        </div>
      }
    </section>
  `,
})
export class SummaryBarComponent {
  private readonly i18n = inject(I18nService);

  readonly bookings = input.required<readonly Booking[]>();
  readonly ramps = input.required<readonly Ramp[]>();
  readonly stats = input.required<DailyStats>();

  /** Середній час окремим методом: null тут звичайний стан, а не помилка. */
  private avgUnloadLabel(): string {
    const minutes = this.stats().avgWaitMinutes;
    return minutes === null
      ? this.i18n.translate('stats.noValue')
      : this.i18n.translate('stats.avgWaitValue', { minutes });
  }

  readonly items = computed<SummaryItem[]>(() => {
    const bookings = this.bookings();
    const ramps = this.ramps();

    // Рампа зайнята, поки на ній щось розвантажують. Прибув-і-чекає рампу не
    // займає — інакше «завантаженість» показувала б чергу, а не роботу.
    const busy = new Set(
      bookings.filter((b) => b.status === 'unloading').map((b) => b.rampId),
    ).size;
    const queue = bookings.filter((b) => b.status === 'arrived').length;

    return [
      {
        key: 'summary.ramps',
        value: `${busy} / ${ramps.length}`,
        share:
          ramps.length > 0
            ? `${Math.round((busy / ramps.length) * 100)}%`
            : '',
        icon: ICON_RAMP,
      },
      {
        key: 'summary.queue',
        value: this.i18n.translate('summary.queueValue', { n: queue }),
        share: '',
        icon: ICON_TRUCK,
      },
      {
        key: 'summary.avgUnload',
        value: this.avgUnloadLabel(),
        share: '',
        icon: ICON_CLOCK,
      },
      {
        key: 'summary.walkIn',
        value: String(this.stats().walkIn),
        share: '',
        icon: ICON_PLUS,
      },
    ];
  });
}

const ICON_RAMP =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 19h18M5 19V9h6v10M13 19v-6h6v6"/></svg>';
const ICON_TRUCK =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>';
const ICON_CLOCK =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>';
const ICON_PLUS =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>';
