import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core';
import {
  Ramp,
  ReceivingWindow,
  SLOT_SIZES,
  SlotSizeMinutes,
} from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  canDeleteRamp,
  countDailySlots,
  validateRamps,
} from '../../../core/utils/store-config.util';
import { validateRampName } from '../../../core/utils/validators.util';

export interface SlotsChange {
  readonly slotSizeMinutes: SlotSizeMinutes | null;
  readonly ramps: readonly Ramp[];
}

/** Вкладка «Слоти»: розмір слоту 15/20/30/60 і рампи (STC-20…STC-23). */
@Component({
  selector: 'app-store-slots-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './slots-tab.component.html',
})
export class StoreSlotsTabComponent {
  readonly storeId = input.required<string>();
  readonly slotSizeMinutes = input.required<SlotSizeMinutes | null>();
  readonly ramps = input.required<readonly Ramp[]>();
  readonly windows = input.required<readonly ReceivingWindow[]>();
  readonly canEdit = input(false);
  readonly changed = output<SlotsChange>();

  protected readonly sizes = SLOT_SIZES;
  protected readonly draftSize = signal<SlotSizeMinutes | null>(null);
  protected readonly draftRamps = signal<Ramp[]>([]);
  protected readonly rampError = signal<string | null>(null);

  protected readonly errors = computed(() => validateRamps(this.draftRamps()));
  protected readonly enabledCount = computed(
    () => this.draftRamps().filter((r) => r.enabled).length,
  );

  /** STC-23: попередній перегляд сітки слотів на типовий робочий день. */
  protected readonly previewCount = computed(() => {
    const size = this.draftSize();
    if (size === null) {
      return 0;
    }
    const weekday = this.windows().find((w) => w.intervals.length > 0);
    if (!weekday) {
      return 0;
    }
    return countDailySlots(weekday.intervals, size, this.enabledCount());
  });

  constructor() {
    effect(() => {
      this.draftSize.set(this.slotSizeMinutes());
      this.draftRamps.set(this.ramps().map((r) => ({ ...r })));
    });
  }

  protected setSize(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.draftSize.set(raw === '' ? null : (Number(raw) as SlotSizeMinutes));
    this.emit();
  }

  protected addRamp(): void {
    const nextNumber =
      this.draftRamps().reduce((max, r) => Math.max(max, r.number), 0) + 1;
    this.draftRamps.update((ramps) => [
      ...ramps,
      {
        id: `${this.storeId()}-ramp-${nextNumber}-${Date.now()}`,
        number: nextNumber,
        name: null,
        enabled: true,
        disabledFrom: null,
        hasBookings: false,
      },
    ]);
    this.emit();
  }

  protected renameRamp(id: string, name: string): void {
    const error = validateRampName(name);
    this.rampError.set(error);
    if (error !== null) {
      return;
    }
    this.draftRamps.update((ramps) =>
      ramps.map((r) => (r.id === id ? { ...r, name: name.trim() || null } : r)),
    );
    this.emit();
  }

  protected setRampNumber(id: string, raw: string): void {
    const number = Number(raw);
    this.draftRamps.update((ramps) =>
      ramps.map((r) => (r.id === id ? { ...r, number } : r)),
    );
    this.emit();
  }

  protected toggleRamp(id: string, enabled: boolean): void {
    this.draftRamps.update((ramps) =>
      ramps.map((r) => (r.id === id ? { ...r, enabled } : r)),
    );
    this.emit();
  }

  /** STC-22: рампу з історією бронювань видалити не можна — лише вимкнути. */
  protected removeRamp(ramp: Ramp): void {
    if (!canDeleteRamp(ramp)) {
      this.rampError.set('slots.error.rampHasBookings');
      return;
    }
    this.rampError.set(null);
    this.draftRamps.update((ramps) => ramps.filter((r) => r.id !== ramp.id));
    this.emit();
  }

  protected canDelete(ramp: Ramp): boolean {
    return canDeleteRamp(ramp);
  }

  private emit(): void {
    this.changed.emit({
      slotSizeMinutes: this.draftSize(),
      ramps: this.draftRamps(),
    });
  }
}
