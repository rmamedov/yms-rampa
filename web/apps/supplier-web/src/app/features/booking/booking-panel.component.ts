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
import { Observable, map, of, switchMap } from 'rxjs';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { BookingApi, VehicleApi } from '../../core/api/contracts';
import { ERROR_CODES, toProblem } from '../../core/api/problem';
import type {
  Booking,
  BranchDetail,
  CreateBookingRequest,
  HoldSession,
  SlotKey,
  Vehicle,
  VehicleInput,
} from '../../core/models/models';
import { canExtendHold, holdServerNow } from '../../core/util/hold';
import {
  formatCountdown,
  kyivDayLabel,
  kyivDateIso,
  kyivTimeHm,
} from '../../core/util/kyiv-time';
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
    this.clockSkewMs = Date.now() - holdServerNow(hold).getTime();
    this.tick();
    this.timer = setInterval(() => this.tick(), 1000);
    this.heartbeatTimer = setInterval(() => this.heartbeat(), HEARTBEAT_MS);

    this.restoreDraft();
    this.vehiclesApi.list().subscribe({
      next: (list) => {
        this.vehicles.set(list);
        // Знімок авто в бронюванні зберігає лише держномер (DATA-13),
        // тому авто для перенесення шукаємо саме за ним.
        const source = this.transfer.source();
        if (!this.selectedVehicleId() && source) {
          const match = list.find(
            (v) => v.plateNumber === source.vehicle.plateNumber,
          );
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

  private slotKey(hold: HoldSession): SlotKey {
    return {
      storeId: hold.storeId,
      rampId: hold.rampId,
      slotStart: hold.slotStart,
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
    if (
      this.expired() ||
      !canExtendHold(hold, new Date(Date.now() - this.clockSkewMs))
    ) {
      return;
    }
    this.bookingApi.extendHold(this.slotKey(hold), hold.holdToken).subscribe({
      next: (updated) => {
        this.clockSkewMs = Date.now() - holdServerNow(updated).getTime();
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

    // booking-service приймає СНІМОК авто; довідник машин живе в
    // partner-service, тому нове авто спершу заводимо там.
    this.resolveVehicle()
      .pipe(
        switchMap((vehicle) => {
          const request: CreateBookingRequest = {
            storeId: hold.storeId,
            rampId: hold.rampId,
            slotStart: hold.slotStart,
            holdToken: hold.holdToken,
            vehicle,
            orderId: this.orderId().trim() || undefined,
            palletsCount: this.pallets() ?? 0,
            driverId: source?.driverId ?? undefined,
            confirmConflict: withConflict,
          };
          return source
            ? this.bookingApi.reschedule(source.id, request)
            : this.bookingApi.create(request);
        }),
      )
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
            case ERROR_CODES.holdNotOwned:
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

  /** Обране авто з довідника або щойно створене. */
  private resolveVehicle(): Observable<VehicleInput> {
    if (!this.addingVehicle()) {
      const selected = this.selectedVehicle();
      return of({
        plateNumber: selected?.plateNumber ?? '',
        weightTons: selected?.weightTons ?? 0,
        brand: selected?.brand ?? undefined,
      });
    }
    return this.vehiclesApi.create(this.newVehicleInput()).pipe(
      map((vehicle) => ({
        plateNumber: vehicle.plateNumber,
        weightTons: vehicle.weightTons,
        brand: vehicle.brand ?? undefined,
      })),
    );
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
        .releaseHold(this.slotKey(hold), hold.holdToken)
        .subscribe({ error: () => undefined });
    }
  }
}
