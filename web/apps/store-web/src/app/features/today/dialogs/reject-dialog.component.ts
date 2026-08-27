import {
  ChangeDetectionStrategy,
  Component,
  computed,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ModalComponent } from '../../../shared/modal.component';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  REJECT_REASONS,
  RejectPayload,
  RejectReason,
} from '../../../core/models/booking.model';
import { validateRejectForm } from '../../../core/util/booking-rules.util';

/** Відмова в прийомі з причиною з довідника (STW-35). */
@Component({
  selector: 'app-reject-dialog',
  standalone: true,
  imports: [FormsModule, ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-modal titleKey="reject.title" (closed)="closed.emit()">
      <div class="field">
        <label class="field__label" for="reject-reason">{{
          'reject.reason' | t
        }}</label>
        <select
          id="reject-reason"
          class="select"
          [ngModel]="reason()"
          (ngModelChange)="reason.set($event)"
        >
          <option [ngValue]="null">—</option>
          @for (item of reasons; track item) {
            <option [ngValue]="item">{{ 'rejectReason.' + item | t }}</option>
          }
        </select>
      </div>

      <div class="field">
        <label class="field__label" for="reject-comment">{{
          'common.comment' | t
        }}</label>
        <textarea
          id="reject-comment"
          class="textarea"
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
        <button type="button" class="btn btn--danger" (click)="submit()">
          {{ 'action.reject' | t }}
        </button>
      </div>
    </app-modal>
  `,
})
export class RejectDialogComponent {
  readonly confirmed = output<RejectPayload>();
  readonly closed = output<void>();

  readonly reasons = REJECT_REASONS;
  readonly reason = signal<RejectReason | null>(null);
  readonly comment = signal('');
  readonly submitted = signal(false);

  readonly errors = computed(() =>
    validateRejectForm({ reason: this.reason(), comment: this.comment() }),
  );

  submit(): void {
    this.submitted.set(true);
    if (!this.errors().valid) return;
    this.confirmed.emit({
      reason: this.reason() as RejectReason,
      comment: this.comment().trim() || null,
    });
  }
}
