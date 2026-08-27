import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  OnInit,
  output,
  signal,
} from '@angular/core';
import { ModalComponent } from '../../../shared/modal.component';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { AuditEntry, Booking } from '../../../core/models/booking.model';
import { StoreGateway } from '../../../core/data/gateways';
import { I18nService } from '../../../core/i18n/i18n.service';
import { formatDate, formatTime } from '../../../core/util/date.util';

interface AuditRow {
  readonly id: string;
  readonly when: string;
  readonly actor: string;
  readonly action: string;
  readonly change: string;
}

/** Журнал дій бронювання — read-only (STW-33). */
@Component({
  selector: 'app-audit-dialog',
  standalone: true,
  imports: [ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-modal titleKey="log.title" (closed)="closed.emit()">
      @if (loading()) {
        <p class="muted">{{ 'common.loading' | t }}</p>
      } @else if (rows().length === 0) {
        <p class="muted">{{ 'log.empty' | t }}</p>
      } @else {
        <div class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>{{ 'log.time' | t }}</th>
                <th>{{ 'log.actor' | t }}</th>
                <th>{{ 'log.action' | t }}</th>
                <th>{{ 'log.change' | t }}</th>
              </tr>
            </thead>
            <tbody>
              @for (row of rows(); track row.id) {
                <tr>
                  <td>{{ row.when }}</td>
                  <td>{{ row.actor }}</td>
                  <td>{{ row.action }}</td>
                  <td>{{ row.change }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }

      <div class="modal__actions">
        <button type="button" class="btn" (click)="closed.emit()">
          {{ 'common.close' | t }}
        </button>
      </div>
    </app-modal>
  `,
})
export class AuditDialogComponent implements OnInit {
  private readonly gateway = inject(StoreGateway);
  private readonly i18n = inject(I18nService);

  readonly booking = input.required<Booking>();
  readonly closed = output<void>();

  readonly entries = signal<readonly AuditEntry[]>([]);
  readonly loading = signal(true);

  readonly rows = computed<AuditRow[]>(() =>
    this.entries().map((entry) => ({
      id: entry.id,
      when: `${formatDate(entry.at)} ${formatTime(entry.at)}`,
      actor: this.actorLabel(entry),
      action: this.i18n.translate(`log.action.${entry.action}`),
      change: this.changeLabel(entry),
    })),
  );

  ngOnInit(): void {
    this.fetch();
  }

  private fetch(): void {
    this.gateway.getAuditLog(this.booking().id).subscribe({
      next: (entries) => {
        this.entries.set(entries);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  private actorLabel(entry: AuditEntry): string {
    if (entry.actorKind === 'system_cron') {
      return this.i18n.translate('log.actor.system_cron');
    }
    if (entry.actorKind === 'driver') {
      return `${entry.actorName} · ${this.i18n.translate('log.actor.driver')}`;
    }
    if (entry.actorKind === 'supplier') {
      return `${entry.actorName} · ${this.i18n.translate('log.actor.supplier')}`;
    }
    const role = entry.actorRole
      ? this.i18n.translate(`header.role.${entry.actorRole}`)
      : '';
    return role ? `${entry.actorName} · ${role}` : entry.actorName;
  }

  private changeLabel(entry: AuditEntry): string {
    const render = (value: string | null) => {
      if (!value) return '—';
      if (this.i18n.has(`status.${value}`)) {
        return this.i18n.translate(`status.${value}`);
      }
      if (/^\d{4}-\d{2}-\d{2}T/.test(value)) return formatTime(value);
      return value;
    };
    const change = `${render(entry.fromValue)} → ${render(entry.toValue)}`;
    return entry.comment ? `${change} (${entry.comment})` : change;
  }
}
