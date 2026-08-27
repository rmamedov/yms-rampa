import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ModalComponent } from '../../../shared/modal.component';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  Booking,
  DELAY_REASONS,
  DelayPayload,
  DelayReason,
} from '../../../core/models/booking.model';
import { validateDelayForm } from '../../../core/util/booking-rules.util';
import {
  formatTime,
  kyivToUtcIso,
  parseHhMm,
  toKyivDateKey,
} from '../../../core/util/date.util';

/** Повідомлення про затримку (STW-18…21). */
@Component({
  selector: 'app-delay-dialog',
  standalone: true,
  imports: [FormsModule, ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-modal titleKey="delay.title" (closed)="closed.emit()">
      <p class="muted">
        {{ booking().supplierNameSnapshot }} ·
        {{ slotStartLabel() }}
      </p>

      <div class="field">
        <label class="field__label" for="delay-reason">{{
          'delay.reason' | t
        }}</label>
        <select
          id="delay-reason"
          class="select"
          [ngModel]="reason()"
          (ngModelChange)="reason.set($event)"
        >
          <option [ngValue]="null">—</option>
          @for (item of reasons; track item) {
            <option [ngValue]="item">{{ 'delayReason.' + item | t }}</option>
          }
        </select>
      </div>

      <div class="field">
        <label class="field__label" for="delay-eta">{{ 'delay.eta' | t }}</label>
        <input
          id="delay-eta"
          class="input"
          type="time"
          [ngModel]="etaTime()"
          (ngModelChange)="etaTime.set($event)"
        />
      </div>

      <div class="field">
        <label class="field__label" for="delay-comment">{{
          'delay.comment' | t
        }}</label>
        <textarea
          id="delay-comment"
          class="textarea"
          maxlength="500"
          [ngModel]="comment()"
          (ngModelChange)="comment.set($event)"
        ></textarea>
      </div>

      @if (submitted() && !errors().valid) {
        @for (error of errors().errors; track error) {
          <p class="form-error">{{ error | t }}</p>
        }
      }

      <div class="modal__actions">
        <button type="button" class="btn" (click)="closed.emit()">
          {{ 'common.cancel' | t }}
        </button>
        <button type="button" class="btn btn--primary" (click)="submit()">
          {{ 'common.save' | t }}
        </button>
      </div>
    </app-modal>
  `,
})
export class DelayDialogComponent {
  readonly booking = input.required<Booking>();
  readonly confirmed = output<DelayPayload>();
  readonly closed = output<void>();

  readonly reasons = DELAY_REASONS;
  readonly reason = signal<DelayReason | null>(null);
  readonly comment = signal('');
  readonly etaTime = signal('');
  readonly submitted = signal(false);

  readonly slotStartLabel = computed(() => formatTime(this.booking().slotStart));

  readonly etaIso = computed(() => {
    const value = this.etaTime();
    if (!/^\d{2}:\d{2}$/.test(value)) return null;
    return kyivToUtcIso(
      toKyivDateKey(this.booking().slotStart),
      parseHhMm(value),
    );
  });

  readonly errors = computed(() =>
    validateDelayForm(
      {
        reason: this.reason(),
        comment: this.comment(),
        eta: this.etaIso(),
      },
      this.booking(),
    ),
  );

  submit(): void {
    this.submitted.set(true);
    if (!this.errors().valid) return;
    this.confirmed.emit({
      reason: this.reason() as DelayReason,
      comment: this.comment().trim() || null,
      eta: this.etaIso() as string,
    });
  }
}
