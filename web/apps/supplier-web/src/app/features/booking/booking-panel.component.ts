import {
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  OnInit,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { BookingApi, VehicleApi } from '../../core/api/contracts';
import { ERROR_CODES, toProblem } from '../../core/api/problem';
import type {
  Booking,
  BranchDetail,
  Vehicle,
  VehicleInput,
} from '../../core/models/models';
import type { HoldSession } from '../../core/models/models';
import { canExtendHold } from '../../core/util/hold';
import { formatCountdown, kyivDayLabel, kyivDateIso, kyivTimeHm } from '../../core/util/kyiv-time';
import { filterVehicles } from '../../core/util/search';
import {
  isStandardPlate,
  normalizePlate,
  validateOrderId,
  validatePallets,
  validatePlate,
  validateVehicleAgainstStore,
} from '../../core/util/validation';
import { ToastService } from '../../shared/ui/toast.service';
import {
  BookingDraftService,
  type BookingDraft,
} from '../../core/services/booking-draft.service';
import { TransferService } from '../../core/services/transfer.service';

const HEARTBEAT_MS = 60_000;

@Component({
  selector: 'app-booking-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './booking-panel.component.html',
  styleUrl: './booking-panel.component.scss',
})
export class BookingPanelComponent implements OnInit, OnDestroy {
  private readonly vehiclesApi = inject(VehicleApi);
  private readonly bookingApi = inject(BookingApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly drafts = inject(BookingDraftService);
  private readonly transfer = inject(TransferService);

  readonly store = input.required<BranchDetail>();
  readonly hold = input.required<HoldSession>();

  readonly booked = output<Booking>();
  readonly closed = output<void>();
  /** 409 на підтвердженні — батьківський екран оновлює сітку. */
  readonly conflicted = output<void>();

  protected readonly vehicles = signal<readonly Vehicle[]>([]);
  protected readonly vehicleQuery = signal('');
  protected readonly selectedVehicleId = signal<string | null>(null);
  protected readonly addingVehicle = signal(false);
  protected readonly newPlate = signal('');
  protected readonly newBrand = signal('');
  protected readonly newWeight = signal<number | null>(null);
  protected readonly orderId = signal('');
  protected readonly pallets = signal<number | null>(null);
  protected readonly submitting = signal(false);
  protected readonly expired = signal(false);
  protected readonly remaining = signal(0);
  protected readonly conflictPrompt = signal(false);

  /** Оновлений hold після heartbeat (input лишається початковим). */
  private readonly holdOverride = signal<HoldSession | null>(null);
  private timer: ReturnType<typeof setInterval> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private clockSkewMs = 0;
  private confirmed = false;

  protected readonly transferSource = this.transfer.source;

  protected readonly filteredVehicles = computed(() =>
    filterVehicles(
      this.vehicles().filter((vehicle) => vehicle.active),
      this.vehicleQuery(),
    ),
  );

  protected readonly selectedVehicle = computed(
    () =>
      this.vehicles().find((v) => v.id === this.selectedVehicleId()) ?? null,
  );

  protected readonly effectiveWeight = computed<number | null>(() =>
    this.addingVehicle()
      ? this.newWeight()
      : (this.selectedVehicle()?.weightTons ?? null),
  );

  protected readonly plateError = computed(() =>
    this.addingVehicle() ? validatePlate(this.newPlate()) : null,
  );

  protected readonly plateHint = computed(
    () =>
      this.addingVehicle() &&
      !this.plateError() &&
      !isStandardPlate(this.newPlate()),
  );

  protected readonly duplicatePlate = computed(() => {
    if (!this.addingVehicle()) {
      return false;
    }
    const plate = normalizePlate(this.newPlate());
    return !!plate && this.vehicles().some((v) => v.plateNumber === plate);
  });

  protected readonly weightError = computed(() => {
    const weight = this.effectiveWeight();
    if (weight === null) {
      return this.addingVehicle() ? 'validation.weightRequired' : null;
    }
    return validateVehicleAgainstStore(
      weight,
      this.store().maxVehicleWeightTons,
    );
  });

  protected readonly palletsError = computed(() =>
    this.pallets() === null ? null : validatePallets(this.pallets()),
  );

  protected readonly orderIdError = computed(() =>
    validateOrderId(this.orderId()),
  );

  protected readonly hasVehicle = computed(() =>
    this.addingVehicle()
      ? !this.plateError() && !this.duplicatePlate() && this.newWeight() !== null
      : this.selectedVehicleId() !== null,
  );

  protected readonly canSubmit = computed(
    () =>
      !this.expired() &&
      !this.submitting() &&
      this.hasVehicle() &&
      !this.weightError() &&
      this.pallets() !== null &&
      !this.palletsError() &&
      !this.orderIdError(),
  );

  protected readonly countdown = computed(() =>
    formatCountdown(this.remaining()),
  );

  protected readonly newWeightText = computed(() =>
    this.newWeight() === null ? '' : String(this.newWeight()),
  );

  protected readonly palletsText = computed(() =>
    this.pallets() === null ? '' : String(this.pallets()),
  );

  /** Підпис бронювання, яке переноситься (SUP-RS-03). */
  protected readonly transferLabel = computed(() => {
    const source = this.transfer.source();
    if (!source) {
      return null;
    }
    return {
      date: kyivDayLabel(kyivDateIso(new Date(source.slotStart))),
      time: kyivTimeHm(new Date(source.slotStart)),
    };
  });

  protected setNewWeight(raw: string): void {
    const value = Number(raw.replace(',', '.'));
    this.newWeight.set(raw.trim() === '' || Number.isNaN(value) ? null : value);
  }

  protected setPallets(raw: string): void {
    const value = Number(raw);
    this.pallets.set(raw.trim() === '' || Number.isNaN(value) ? null : value);
  }

  protected readonly slotDateLabel = computed(() =>
    kyivDayLabel(kyivDateIso(new Date(this.hold().slotStart))),
  );

  protected readonly slotTimeLabel = computed(() =>
    kyivTimeHm(new Date(this.hold().slotStart)),
  );

  protected readonly rampName = computed(
    () =>
      this.store().ramps.find((r) => r.rampId === this.hold().rampId)?.name ??
      '',
  );

  ngOnInit(): void {
    const hold = this.hold();
    this.clockSkewMs = Date.now() - new Date(hold.now).getTime();
    this.tick();
    this.timer = setInterval(() => this.tick(), 1000);
    this.heartbeatTimer = setInterval(() => this.heartbeat(), HEARTBEAT_MS);

    this.restoreDraft();
    this.vehiclesApi.list().subscribe({
      next: (list) => {
        this.vehicles.set(list);
        const source = this.transfer.source();
        if (!this.selectedVehicleId() && source?.vehicle.vehicleId) {
          const match = list.find((v) => v.id === source.vehicle.vehicleId);
          if (match) {
            this.selectedVehicleId.set(match.id);
          }
        }
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  private restoreDraft(): void {
    const draft = this.drafts.draft();
    const source = this.transfer.source();
    this.selectedVehicleId.set(draft.vehicleId);
    this.orderId.set(draft.orderId || (source?.orderId ?? ''));
    this.pallets.set(draft.palletsCount ?? source?.palletsCount ?? null);
    if (draft.newVehicle) {
      this.addingVehicle.set(true);
      this.newPlate.set(draft.newVehicle.plateNumber);
      this.newBrand.set(draft.newVehicle.brand ?? '');
      this.newWeight.set(draft.newVehicle.weightTons);
    }
  }

  private currentDraft(): BookingDraft {
    return {
      vehicleId: this.selectedVehicleId(),
      newVehicle: this.addingVehicle() ? this.newVehicleInput() : null,
      orderId: this.orderId(),
      palletsCount: this.pallets(),
    };
  }

  private newVehicleInput(): VehicleInput {
    return {
      plateNumber: normalizePlate(this.newPlate()),
      brand: this.newBrand().trim() || undefined,
      weightTons: this.newWeight() ?? 0,
    };
  }

  /** SUP-BOOK-01: зворотний таймер життя hold. */
  private tick(): void {
    const current = this.holdOverride() ?? this.hold();
    const expiresAt = new Date(current.expiresAt).getTime();
    const now = Date.now() - this.clockSkewMs;
    const seconds = Math.max(0, Math.floor((expiresAt - now) / 1000));
    this.remaining.set(seconds);
    if (seconds === 0 && !this.expired()) {
      this.expired.set(true);
      this.toasts.error(this.i18n.t('book.expired'));
      this.stopTimers();
    }
  }

  /** HOLD-02: продовження при активності, але не далі holdMaxMinutes. */
  protected heartbeat(): void {
    const hold = this.holdOverride() ?? this.hold();
    if (this.expired() || !canExtendHold(hold, new Date(Date.now() - this.clockSkewMs))) {
      return;
    }
    this.bookingApi.heartbeat(hold.holdToken).subscribe({
      next: (updated) => {
        this.clockSkewMs = Date.now() - new Date(updated.now).getTime();
        this.holdOverride.set(updated);
        this.tick();
      },
      error: () => {
        this.expired.set(true);
        this.stopTimers();
      },
    });
  }

  protected select(vehicleId: string): void {
    this.selectedVehicleId.set(vehicleId);
    this.addingVehicle.set(false);
  }

  protected toggleAddVehicle(): void {
    this.addingVehicle.update((value) => !value);
    if (this.addingVehicle()) {
      this.selectedVehicleId.set(null);
    }
  }

  protected submit(withConflict = false): void {
    if (!this.canSubmit()) {
      return;
    }
    this.submitting.set(true);
    this.conflictPrompt.set(false);
    const source = this.transfer.source();
    const hold = this.holdOverride() ?? this.hold();

    this.bookingApi
      .create({
        storeId: hold.storeId,
        rampId: hold.rampId,
        slotStart: hold.slotStart,
        holdToken: hold.holdToken,
        vehicleId: this.addingVehicle()
          ? undefined
          : (this.selectedVehicleId() ?? undefined),
        newVehicle: this.addingVehicle() ? this.newVehicleInput() : undefined,
        orderId: this.orderId().trim() || undefined,
        palletsCount: this.pallets() ?? 0,
        confirmConflict: withConflict,
        transferFromBookingId: source?.id,
      })
      .subscribe({
        next: (booking) => {
          this.submitting.set(false);
          this.confirmed = true;
          this.drafts.reset();
          this.toasts.success(
            this.i18n.t(source ? 'book.transferSuccess' : 'book.success'),
          );
          this.transfer.clear();
          this.booked.emit(booking);
        },
        error: (error: unknown) => {
          this.submitting.set(false);
          const problem = toProblem(error);
          this.drafts.save(this.currentDraft());
          switch (problem.code) {
            case ERROR_CODES.vehicleTimeConflict:
              this.conflictPrompt.set(true);
              return;
            case ERROR_CODES.slotAlreadyBooked:
              this.toasts.error(this.toasts.messageFor(problem));
              this.confirmed = true;
              this.conflicted.emit();
              return;
            case ERROR_CODES.holdExpired:
              this.expired.set(true);
              this.stopTimers();
              this.toasts.error(this.toasts.messageFor(problem));
              return;
            default:
              this.toasts.error(this.toasts.messageFor(problem));
          }
        },
      });
  }

  protected close(): void {
    this.drafts.save(this.currentDraft());
    this.closed.emit();
  }

  private stopTimers(): void {
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }

  ngOnDestroy(): void {
    this.stopTimers();
    // SUP-BOOK-08: закриття панелі без підтвердження негайно знімає hold.
    if (!this.confirmed && !this.expired()) {
      const hold = this.holdOverride() ?? this.hold();
      this.bookingApi
        .release(hold.holdToken)
        .subscribe({ error: () => undefined });
    }
  }
}
