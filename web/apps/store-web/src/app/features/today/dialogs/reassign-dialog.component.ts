import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ModalComponent } from '../../../shared/modal.component';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { Booking, ReassignPayload } from '../../../core/models/booking.model';
import { BoardStore } from '../../../core/data/board.store';

/** Разове переведення на іншу рампу того самого слота (STW-41/42). */
@Component({
  selector: 'app-reassign-dialog',
  standalone: true,
  imports: [FormsModule, ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-modal titleKey="reassign.title" (closed)="closed.emit()">
      <p class="muted">
        {{ 'reassign.current' | t: { name: currentRampName() } }}
      </p>

      @if (freeRamps().length === 0) {
        <p class="form-error">{{ 'reassign.none' | t }}</p>
      } @else {
        <div class="field">
          <label class="field__label" for="target-ramp">{{
            'reassign.pick' | t
          }}</label>
          <select
            id="target-ramp"
            class="select"
            [ngModel]="target()"
            (ngModelChange)="target.set($event)"
          >
            <option [ngValue]="null">—</option>
            @for (ramp of freeRamps(); track ramp.rampId) {
              <option [ngValue]="ramp.rampId">{{ ramp.name }}</option>
            }
          </select>
        </div>
      }

      <div class="modal__actions">
        <button type="button" class="btn" (click)="closed.emit()">
          {{ 'common.cancel' | t }}
        </button>
        <button
          type="button"
          class="btn btn--primary"
          [disabled]="!target()"
          (click)="submit()"
        >
          {{ 'common.confirm' | t }}
        </button>
      </div>
    </app-modal>
  `,
})
export class ReassignDialogComponent {
  private readonly store = inject(BoardStore);

  readonly booking = input.required<Booking>();
  readonly confirmed = output<ReassignPayload>();
  readonly closed = output<void>();

  readonly target = signal<string | null>(null);

  readonly freeRamps = computed(() => this.store.freeRampsFor(this.booking()));

  readonly currentRampName = computed(
    () =>
      this.store.ramps().find((r) => r.rampId === this.booking().rampId)?.name ??
      this.booking().rampId,
  );

  submit(): void {
    const rampId = this.target();
    if (!rampId) return;
    this.confirmed.emit({ rampId });
  }
}
