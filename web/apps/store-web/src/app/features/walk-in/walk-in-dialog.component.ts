import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ModalComponent } from '../../shared/modal.component';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { WalkInPayload } from '../../core/models/booking.model';
import { Slot } from '../../core/models/store.model';
import { BoardStore } from '../../core/data/board.store';
import { AuthService } from '../../core/auth/auth.service';
import { StoreGateway } from '../../core/data/gateways';
import { validateWalkInForm } from '../../core/util/booking-rules.util';
import { formatTime, toKyivDateKey } from '../../core/util/date.util';

interface FreeSlotOption {
  readonly value: string;
  readonly label: string;
  readonly rampId: string;
  readonly slotStart: string;
}

/** Реєстрація позапланового прибуття (STW-37…39). */
@Component({
  selector: 'app-walk-in-dialog',
  standalone: true,
  imports: [FormsModule, ModalComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './walk-in-dialog.component.html',
})
export class WalkInDialogComponent implements OnInit {
  private readonly store = inject(BoardStore);
  private readonly auth = inject(AuthService);
  private readonly gateway = inject(StoreGateway);

  readonly closed = output<void>();

  readonly suppliers = this.store.suppliers;
  readonly maxWeight = computed(
    () => this.store.config()?.maxVehicleWeightTons ?? 10,
  );

  readonly useExternal = signal(false);
  readonly supplierId = signal<string | null>(null);
  readonly externalName = signal('');
  readonly plate = signal('');
  readonly weight = signal<number | null>(null);
  readonly pallets = signal<number | null>(null);
  readonly orderId = signal('');
  readonly slotKey = signal<string | null>(null);
  readonly submitted = signal(false);
  readonly slots = signal<readonly Slot[]>([]);
  /**
   * Сітка ще не відповіла. Без цього стану форма відкривалась із написом
   * «немає вільних слотів» ще до першого запиту — тобто повідомляла про
   * відсутність того, чого не встигла спитати.
   */
  readonly slotsLoading = signal(true);

  /**
   * Усі вільні слоти доби, які ще не минули: перелік НЕ обрізається — інакше
   * приймальник не знайшов би слот, який насправді є (STW-38).
   * `selectable` рахує бекенд, тому фільтр не дублює його логіку.
   */
  readonly options = computed<FreeSlotOption[]>(() => {
    const ramps = this.store.ramps();
    const nowMs = Date.now();
    return this.slots()
      .filter(
        (slot) =>
          slot.selectable &&
          new Date(slot.slotEnd).getTime() > nowMs - 30 * 60_000,
      )
      .sort(
        (a, b) =>
          a.slotStart.localeCompare(b.slotStart) ||
          a.rampId.localeCompare(b.rampId),
      )
      .map((slot) => ({
        value: `${slot.rampId}|${slot.slotStart}`,
        rampId: slot.rampId,
        slotStart: slot.slotStart,
        label: `${formatTime(slot.slotStart)}–${formatTime(slot.slotEnd)} · ${
          ramps.find((r) => r.rampId === slot.rampId)?.name ?? slot.rampId
        }`,
      }));
  });

  readonly selectedOption = computed(() =>
    this.options().find((o) => o.value === this.slotKey()) ?? null,
  );

  readonly errors = computed(() =>
    validateWalkInForm(
      {
        supplierId: this.supplierId(),
        externalSupplierName: this.externalName(),
        useExternalSupplier: this.useExternal(),
        plateNumber: this.plate(),
        weightTons: this.weight(),
        palletsCount: this.pallets(),
        orderId: this.orderId(),
        rampId: this.selectedOption()?.rampId ?? null,
        slotStart: this.selectedOption()?.slotStart ?? null,
      },
      this.maxWeight(),
    ),
  );

  readonly busy = computed(() => this.store.busyBookingId() === 'walk-in');

  ngOnInit(): void {
    const store = this.auth.selectedStore();
    if (!store) {
      this.slotsLoading.set(false);
      return;
    }
    this.gateway.getSlots(store.storeId, toKyivDateKey(new Date())).subscribe({
      next: (slots) => {
        this.slots.set(slots);
        this.slotsLoading.set(false);
      },
      // Без сітки форма лишається відкритою і чесно каже, що вибирати нічого.
      error: () => {
        this.slots.set([]);
        this.slotsLoading.set(false);
      },
    });
  }

  setMode(external: boolean): void {
    this.useExternal.set(external);
  }

  submit(): void {
    this.submitted.set(true);
    if (!this.errors().valid) return;
    const option = this.selectedOption();
    if (!option) return;

    // Тіло POST /api/store/v1/bookings/walk-in: авто йде вкладеним обʼєктом
    // `vehicle`, а назва постачальника «поза системою» — полем `supplierName`.
    const payload: Omit<WalkInPayload, 'storeId'> = {
      rampId: option.rampId,
      slotStart: option.slotStart,
      vehicle: {
        plateNumber: this.plate().trim(),
        weightTons: this.weight() as number,
        brand: null,
      },
      palletsCount: this.pallets() as number,
      supplierId: this.useExternal() ? null : this.supplierId(),
      supplierName: this.useExternal() ? this.externalName().trim() : null,
      orderId: this.orderId().trim() || null,
    };
    this.store.createWalkIn(payload, () => this.closed.emit());
  }

  setNumber(target: 'weight' | 'pallets', raw: string): void {
    const parsed = Number(raw);
    const value = raw.trim() === '' || !Number.isFinite(parsed) ? null : parsed;
    if (target === 'weight') {
      this.weight.set(value);
    } else {
      this.pallets.set(value === null ? null : Math.trunc(value));
    }
  }
}
