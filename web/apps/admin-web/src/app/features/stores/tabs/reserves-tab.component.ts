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
  DAYS_OF_WEEK,
  DayOfWeek,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotSizeMinutes,
  Supplier,
} from '../../../core/models';
import { ReservedSlotRuleDraft } from '../../../core/data/stores.api';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { validateReservedRule } from '../../../core/utils/store-config.util';
import { addDays, formatDate, isoToKyivDate, kyivDate } from '../../../core/utils/time.util';

/**
 * Вкладка «Резерви» (STC-40…STC-43).
 * Кожна дія одразу йде на /stores/{id}/reserved-slot-rules — це окремий
 * ресурс бекенду, а не частина конфігурації.
 */
@Component({
  selector: 'app-store-reserves-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './reserves-tab.component.html',
})
export class StoreReservesTabComponent {
  readonly rules = input.required<readonly ReservedSlotRule[]>();
  readonly ramps = input.required<readonly Ramp[]>();
  readonly windows = input.required<readonly ReceivingWindow[]>();
  readonly slotSizeMinutes = input.required<SlotSizeMinutes | null>();
  readonly suppliers = input.required<readonly Supplier[]>();
  readonly canEdit = input(false);

  readonly ruleCreate = output<ReservedSlotRuleDraft>();
  readonly ruleToggle = output<{ id: string; active: boolean }>();
  readonly ruleDelete = output<string>();

  protected readonly days = DAYS_OF_WEEK;
  protected readonly errors = signal<readonly string[]>([]);

  protected readonly mode = signal<'weekly' | 'date'>('weekly');
  protected readonly supplierId = signal('');
  protected readonly dayOfWeek = signal<DayOfWeek>(1);
  protected readonly date = signal(addDays(kyivDate(), 7));
  protected readonly slotStartTime = signal('08:00');
  protected readonly rampId = signal('');
  protected readonly validFrom = signal(addDays(kyivDate(), 1));
  protected readonly validTo = signal('');

  protected readonly formatDate = formatDate;
  protected readonly formatIsoDate = (iso: string | null): string =>
    iso === null ? '∞' : formatDate(isoToKyivDate(iso));

  protected readonly activeSuppliers = computed(() =>
    this.suppliers().filter((s) => s.status === 'active'),
  );
  protected readonly enabledRamps = computed<readonly Ramp[]>(() =>
    this.ramps().filter((r) => r.enabled),
  );

  constructor() {
    effect(() => {
      const ramps = this.ramps().filter((r) => r.enabled);
      if (this.rampId() === '' && ramps.length > 0) {
        this.rampId.set(ramps[0].id);
      }
      const suppliers = this.suppliers().filter((s) => s.status === 'active');
      if (this.supplierId() === '' && suppliers.length > 0) {
        this.supplierId.set(suppliers[0].id);
      }
    });
  }

  protected supplierName(id: string): string {
    return this.suppliers().find((s) => s.id === id)?.name ?? id;
  }

  protected rampLabel(id: string): string {
    const ramp = this.ramps().find((r) => r.id === id);
    return ramp ? (ramp.name ?? `№${ramp.number}`) : id;
  }

  protected addRule(): void {
    const weekly = this.mode() === 'weekly';
    const draft: ReservedSlotRuleDraft = {
      supplierId: this.supplierId(),
      rampId: this.rampId(),
      slotStartTime: this.slotStartTime(),
      dayOfWeek: weekly ? this.dayOfWeek() : null,
      date: weekly ? null : this.date(),
      validFrom: this.validFrom(),
      validTo: this.validTo() === '' ? null : this.validTo(),
      active: true,
    };
    const errors = validateReservedRule(
      draft,
      {
        ramps: this.ramps(),
        receivingWindows: this.windows(),
        slotSizeMinutes: this.slotSizeMinutes(),
      },
      this.rules().map((r) => ({
        id: r.id,
        supplierId: r.supplierId,
        rampId: r.rampId,
        slotStartTime: r.slotStartTime,
        dayOfWeek: r.dayOfWeek,
        date: r.date,
        validFrom: isoToKyivDate(r.validFrom),
        validTo: r.validTo === null ? null : isoToKyivDate(r.validTo),
        active: r.active,
      })),
    );
    this.errors.set(errors);
    if (errors.length > 0) {
      return;
    }
    this.ruleCreate.emit(draft);
  }

  protected toggleActive(id: string, active: boolean): void {
    this.ruleToggle.emit({ id, active });
  }

  protected removeRule(id: string): void {
    this.ruleDelete.emit(id);
  }

  protected setMode(event: Event): void {
    this.mode.set((event.target as HTMLSelectElement).value as 'weekly' | 'date');
  }

  protected setDay(event: Event): void {
    this.dayOfWeek.set(Number((event.target as HTMLSelectElement).value) as DayOfWeek);
  }

  protected setSupplier(event: Event): void {
    this.supplierId.set((event.target as HTMLSelectElement).value);
  }

  protected setRamp(event: Event): void {
    this.rampId.set((event.target as HTMLSelectElement).value);
  }
}
