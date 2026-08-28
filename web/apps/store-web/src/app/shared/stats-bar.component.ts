import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core';
import { DailyStats } from '../core/util/board.util';
import { I18nService } from '../core/i18n/i18n.service';
import { TranslatePipe } from '../core/i18n/translate.pipe';

interface StatTile {
  readonly key: string;
  readonly value: string;
  /** Підпис під числом: частка від плану, «сьогодні» тощо. */
  readonly hint: string;
  readonly tone: string;
  readonly icon: string;
}

/**
 * Денна зведена (STW-24).
 *
 * Кожна картка — те саме число, що й раніше, але з підписом «від чого воно».
 * Голе «6» під словом «Прийшло» нічого не каже; «26% від плану» вже каже.
 * Частки рахуються від загальної кількості прибуттів на день, і коли план
 * порожній, підпис зникає — ділити на нуль ми не вдаємо.
 */
@Component({
  selector: 'app-stats-bar',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="kpi">
      @for (tile of tiles(); track tile.key) {
        <article class="kpi__card" [class]="'kpi__card kpi__card--' + tile.tone">
          <div class="kpi__head">
            <span class="kpi__label">{{ tile.key | t }}</span>
            <span class="kpi__icon" aria-hidden="true" [innerHTML]="tile.icon"></span>
          </div>
          <div class="kpi__value">{{ tile.value }}</div>
          <div class="kpi__hint">{{ tile.hint }}</div>
        </article>
      }
    </div>
  `,
})
export class StatsBarComponent {
  private readonly i18n = inject(I18nService);
  readonly stats = input.required<DailyStats>();
  /** Дата дошки — щоб перша картка казала, за який саме день число. */
  readonly dateLabel = input('');

  readonly tiles = computed<StatTile[]>(() => {
    const s = this.stats();
    const share = (value: number): string =>
      s.total > 0
        ? this.i18n.translate('stats.share', {
            percent: Math.round((value / s.total) * 100),
          })
        : '';
    const today = this.i18n.translate('stats.hint.today');

    return [
      {
        key: 'stats.total',
        value: String(s.total),
        hint: this.dateLabel(),
        tone: 'slate',
        icon: ICON_TRUCK,
      },
      {
        key: 'stats.arrived',
        value: String(s.arrived),
        hint: share(s.arrived),
        tone: 'amber',
        icon: ICON_TRUCK,
      },
      {
        key: 'stats.completed',
        value: String(s.completed),
        hint: today,
        tone: 'green',
        icon: ICON_CHECK,
      },
      {
        key: 'stats.noShow',
        value: String(s.noShow),
        hint: share(s.noShow),
        tone: 'red',
        icon: ICON_BAN,
      },
      {
        key: 'stats.rejected',
        value: String(s.rejected),
        hint: today,
        tone: 'maroon',
        icon: ICON_CROSS,
      },
      {
        key: 'stats.walkIn',
        value: String(s.walkIn),
        hint: share(s.walkIn),
        tone: 'walkin',
        icon: ICON_TRUCK,
      },
      {
        key: 'stats.avgWait',
        value:
          s.avgWaitMinutes === null
            ? this.i18n.translate('stats.noValue')
            : this.i18n.translate('stats.avgWaitValue', {
                minutes: s.avgWaitMinutes,
              }),
        hint: this.i18n.translate('stats.hint.onSite'),
        tone: 'violet',
        icon: ICON_CLOCK,
      },
    ];
  });
}

// Іконки вбудовані рядками: окремих файлів заради шести гліфів заводити не
// варто, а зовнішній пакет тягнув би за собою весь набір.
const ICON_TRUCK =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>';
const ICON_CHECK =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.6 2.5L16 9.5"/></svg>';
const ICON_BAN =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/></svg>';
const ICON_CROSS =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>';
const ICON_CLOCK =
  '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>';
