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
import { I18nService } from '../../../core/i18n/i18n.service';
import { formatDate, formatTime } from '../../../core/util/date.util';

interface AuditRow {
  readonly id: string;
  readonly when: string;
  readonly actor: string;
  readonly change: string;
}

/**
 * Журнал дій бронювання — read-only (STW-33).
 *
 * Окремого ендпоінта аудиту бекенд не має, тому журнал будується з
 * `statusHistory`, який booking-service віддає разом із бронюванням
 * (`BookingPresenter::toArray()`). Бекенд зберігає лише userId ініціатора,
 * тож ПІБ і роль у журналі недоступні.
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
                  <td>{{ row.actor }}</td>
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

  readonly booking = input.required<Booking>();
  readonly closed = output<void>();

  readonly rows = computed<AuditRow[]>(() =>
    [...this.booking().statusHistory]
      .sort((a, b) => a.at.localeCompare(b.at))
      .map((entry, index) => ({
        id: `${entry.at}-${entry.to}-${index}`,
        when: `${formatDate(entry.at)} ${formatTime(entry.at)}`,
        actor: entry.by,
        change: this.changeLabel(entry),
      })),
  );

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
