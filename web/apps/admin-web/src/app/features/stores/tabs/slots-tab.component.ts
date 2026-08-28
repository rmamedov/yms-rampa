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
  SLOT_SIZE_MAX,
  SLOT_SIZE_MIN,
  SLOT_SIZE_STEP,
  SlotSizeMinutes,
} from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  countDailySlots,
  validateRamps,
} from '../../../core/utils/store-config.util';
import { validateRampName, validateSlotSize } from '../../../core/utils/validators.util';

export interface SlotsChange {
  readonly slotSizeMinutes: SlotSizeMinutes | null;
  readonly ramps: readonly Ramp[];
}

/** Секція «Слоти»: розмір слоту (крок 5 хв) і рампи (STC-20…STC-23). */
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

  protected readonly sizeMin = SLOT_SIZE_MIN;
  protected readonly sizeMax = SLOT_SIZE_MAX;
  protected readonly sizeStep = SLOT_SIZE_STEP;
  protected readonly draftSize = signal<SlotSizeMinutes | null>(null);
  protected readonly draftRamps = signal<Ramp[]>([]);
  protected readonly rampError = signal<string | null>(null);

  protected readonly errors = computed(() => validateRamps(this.draftRamps()));
  /** Порожнє поле помилкою не показуємо: про це вже каже зведення біля «Зберегти». */
  protected readonly sizeError = computed(() =>
    this.draftSize() === null ? null : validateSlotSize(this.draftSize()),
  );
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

  protected setSize(raw: string): void {
    const trimmed = raw.trim();
    // Порожнє поле — це «не задано», а не нуль: інакше сітка мовчки будувалася б
    // з безглуздим розміром, замість того щоб заблокувати збереження.
    this.draftSize.set(trimmed === '' ? null : Number(trimmed));
    this.emit();
  }

  protected addRamp(): void {
    const nextNumber =
      this.draftRamps().reduce((max, r) => Math.max(max, r.number), 0) + 1;
    this.draftRamps.update((ramps) => [
      ...ramps,
      {
        id: `${this.storeId()}-ramp-${nextNumber}`,
        number: nextNumber,
        name: null,
        enabled: true,
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

  /**
   * STC-22: рампу можна прибрати з нової версії конфігурації.
   * Наслідки для наявних бронювань перевіряє бекенд під час збереження версії —
   * ознаки «має бронювання» у контракті картки магазину немає.
   */
  protected removeRamp(ramp: Ramp): void {
    this.rampError.set(null);
    this.draftRamps.update((ramps) => ramps.filter((r) => r.id !== ramp.id));
    this.emit();
  }

  private emit(): void {
    this.changed.emit({
      slotSizeMinutes: this.draftSize(),
      ramps: this.draftRamps(),
    });
  }
}
