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
  readonly tone: string;
}

/** Денна зведена статистика (STW-24). */
@Component({
  selector: 'app-stats-bar',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="stats">
      @for (tile of tiles(); track tile.key) {
        <div class="stats__tile" [class]="'stats__tile stats__tile--' + tile.tone">
          <span class="stats__value">{{ tile.value }}</span>
          <span class="stats__label">{{ tile.key | t }}</span>
        </div>
      }
    </div>
  `,
})
export class StatsBarComponent {
  private readonly i18n = inject(I18nService);
  readonly stats = input.required<DailyStats>();

  readonly tiles = computed<StatTile[]>(() => {
    const s = this.stats();
    return [
      { key: 'stats.total', value: String(s.total), tone: 'slate' },
      { key: 'stats.arrived', value: String(s.arrived), tone: 'amber' },
      { key: 'stats.completed', value: String(s.completed), tone: 'green' },
      { key: 'stats.noShow', value: String(s.noShow), tone: 'red' },
      { key: 'stats.rejected', value: String(s.rejected), tone: 'maroon' },
      { key: 'stats.walkIn', value: String(s.walkIn), tone: 'walkin' },
      {
        key: 'stats.avgWait',
        value:
          s.avgWaitMinutes === null
            ? this.i18n.translate('stats.noValue')
            : this.i18n.translate('stats.avgWaitValue', {
                minutes: s.avgWaitMinutes,
              }),
        tone: 'violet',
      },
    ];
  });
}
