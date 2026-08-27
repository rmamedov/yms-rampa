import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
} from '@angular/core';
import { ModalComponent } from '../../../shared/modal.component';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { Booking, StatusChange } from '../../../core/models/booking.model';
import { AuthService } from '../../../core/auth/auth.service';
import { I18nService } from '../../../core/i18n/i18n.service';
import { formatDate, formatTime } from '../../../core/util/date.util';

interface AuditRow {
  readonly id: string;
  readonly when: string;
  /** Людиночитане «Хто»: ПІБ для власних дій, інакше роль виконавця. */
  readonly actor: string;
  /** Технічний рядок під підписом: роль і ідентифікатор облікового запису. */
  readonly actorNote: string;
  readonly change: string;
}

/**
 * Журнал дій бронювання — read-only (STW-33).
 *
 * Окремого ендпоінта аудиту бекенд не має, тому журнал будується з
 * `statusHistory`, який booking-service віддає разом із бронюванням
 * (`BookingPresenter::toArray()`).
 *
 * КОЛОНКА «ХТО». Поле `by` — це UUID облікового запису, і саме він раніше
 * стояв у колонці. Тепер поруч приходять `byRole` / `byContour` / `byLabel`:
 * позначку бере на себе `byLabel` («Керівник магазину», «Водій», «Планове
 * завдання системи»). ПІБ бекенд не зберігає свідомо — шлюз не передає
 * імені, а підроблене імʼя в журналі гірше за його відсутність, — тому ПІБ
 * підставляється лише для дій ПОТОЧНОГО користувача, чиє імʼя застосунок
 * знає з власного профілю. Порожній `byLabel` (записи, зроблені до появи
 * поля) лишається чесним «невідомо»: ідентифікатор у підпис не повертаємо,
 * він лишається технічним рядком нижче.
 */
@Component({
  selector: 'app-audit-dialog',
  standalone: true,
  imports: [ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-modal titleKey="log.title" (closed)="closed.emit()">
      @if (rows().length === 0) {
        <p class="muted">{{ 'log.empty' | t }}</p>
      } @else {
        <div class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>{{ 'log.time' | t }}</th>
                <th>{{ 'log.actor' | t }}</th>
                <th>{{ 'log.change' | t }}</th>
              </tr>
            </thead>
            <tbody>
              @for (row of rows(); track row.id) {
                <tr>
                  <td>{{ row.when }}</td>
                  <td>
                    <span class="log__actor">{{ row.actor }}</span>
                    <span class="log__actorid muted">{{ row.actorNote }}</span>
                  </td>
                  <td>{{ row.change }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }

      <p class="muted">{{ 'log.sourceNote' | t }}</p>

      <div class="modal__actions">
        <button type="button" class="btn" (click)="closed.emit()">
          {{ 'common.close' | t }}
        </button>
      </div>
    </app-modal>
  `,
})
export class AuditDialogComponent {
  private readonly i18n = inject(I18nService);
  private readonly auth = inject(AuthService);

  readonly booking = input.required<Booking>();
  readonly closed = output<void>();

  readonly rows = computed<AuditRow[]>(() =>
    [...this.booking().statusHistory]
      .sort((a, b) => a.at.localeCompare(b.at))
      .map((entry, index) => {
        const actor = this.actorOf(entry);
        return {
          id: `${entry.at}-${entry.to}-${index}`,
          when: `${formatDate(entry.at)} ${formatTime(entry.at)}`,
          actor: actor.label,
          actorNote: actor.note,
          change: this.changeLabel(entry),
        };
      }),
  );

  private actorOf(entry: StatusChange): { label: string; note: string } {
    const profile = this.auth.profile();
    const isSelf = profile !== null && profile.userId === entry.by;
    const label = isSelf
      ? profile.fullName
      : (entry.byLabel ?? this.i18n.translate('log.actorUnknown'));

    const note: string[] = [];
    // Роль показуємо окремо лише тоді, коли підпис уже зайняв ПІБ.
    if (isSelf && entry.byLabel) note.push(entry.byLabel);
    note.push(this.i18n.translate('log.actorId', { id: entry.by }));
    return { label, note: note.join(' · ') };
  }

  private changeLabel(entry: StatusChange): string {
    const render = (value: string | null) =>
      value ? this.i18n.translate(`status.${value}`) : '—';
    const change = `${render(entry.from)} → ${render(entry.to)}`;
    const meta = Object.entries(entry.meta)
      .map(([key, value]) => `${key}: ${String(value)}`)
      .join(', ');
    return meta ? `${change} (${meta})` : change;
  }
}
