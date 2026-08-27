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
  CompleteUnloadingPayload,
  PARTIAL_UNLOAD_REASONS,
  PartialUnloadReason,
} from '../../../core/models/booking.model';
import {
  normalizeCompleteForm,
  validateCompleteForm,
} from '../../../core/util/booking-rules.util';

/** Форма підтвердження розвантаження (STW-14, STW-36). */
@Component({
  selector: 'app-complete-dialog',
  standalone: true,
  imports: [FormsModule, ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './complete-dialog.component.html',
})
export class CompleteDialogComponent {
  readonly booking = input.required<Booking>();
  readonly confirmed = output<CompleteUnloadingPayload>();
  readonly closed = output<void>();

  readonly reasons = PARTIAL_UNLOAD_REASONS;

  readonly unloaded = signal<number | null>(null);
  readonly partialTouched = signal(false);
  readonly reason = signal<PartialUnloadReason | null>(null);
  readonly comment = signal('');
  readonly submitted = signal(false);

  readonly plannedPallets = computed(() => this.booking().palletsCount);

  readonly unloadedValue = computed(
    () => this.unloaded() ?? this.plannedPallets(),
  );

  /** STW-36: недовантаження автоматично вмикає прапорець часткового. */
  readonly partial = computed(() =>
    normalizeCompleteForm(
      {
        unloadedPalletsCount: this.unloadedValue(),
        partialUnload: this.partialTouched(),
        reason: this.reason(),
        comment: this.comment(),
      },
      this.plannedPallets(),
    ).partialUnload,
  );

  readonly errors = computed(() =>
    validateCompleteForm(
      {
        unloadedPalletsCount: this.unloadedValue(),
        partialUnload: this.partialTouched(),
        reason: this.reason(),
        comment: this.comment(),
      },
      this.plannedPallets(),
    ),
  );

  setUnloaded(value: string): void {
    const parsed = Number(value);
    this.unloaded.set(Number.isFinite(parsed) ? Math.trunc(parsed) : null);
  }

  togglePartial(): void {
    this.partialTouched.update((v) => !v);
  }

  submit(): void {
    this.submitted.set(true);
    if (!this.errors().valid) return;
    // Бекенд приймає вкладений обʼєкт partialUnload {reason, comment};
    // окремих полів partialUnload*/flag у тілі запиту немає.
    this.confirmed.emit({
      unloadedPalletsCount: this.unloadedValue(),
      partialUnload:
        this.partial() && this.reason()
          ? {
              reason: this.reason() as PartialUnloadReason,
              comment: this.comment().trim() || null,
            }
          : null,
    });
  }
}
