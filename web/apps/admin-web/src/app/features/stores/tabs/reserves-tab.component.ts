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
  ReservedSlotRule,
  StoreConfig,
  Supplier,
} from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { validateReservedRule } from '../../../core/utils/store-config.util';
import { addDays, formatDate, kyivDate } from '../../../core/utils/time.util';

/** Вкладка «Резерви»: розклад резервних слотів (STC-40…STC-43). */
@Component({
  selector: 'app-store-reserves-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './reserves-tab.component.html',
})
export class StoreReservesTabComponent {
  readonly config = input.required<StoreConfig>();
  readonly suppliers = input.required<readonly Supplier[]>();
  readonly canEdit = input(false);
  readonly changed = output<readonly ReservedSlotRule[]>();

  protected readonly days = DAYS_OF_WEEK;
  protected readonly rules = signal<ReservedSlotRule[]>([]);
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

  protected readonly activeSuppliers = computed(() =>
    this.suppliers().filter((s) => s.status === 'active'),
  );
  protected readonly enabledRamps = computed<readonly Ramp[]>(() =>
    this.config().ramps.filter((r) => r.enabled),
  );

  constructor() {
    effect(() => {
      this.rules.set(this.config().reservedRules.map((r) => ({ ...r })));
      const ramps = this.config().ramps.filter((r) => r.enabled);
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
    const ramp = this.config().ramps.find((r) => r.id === id);
    return ramp ? (ramp.name ?? `№${ramp.number}`) : id;
  }

  protected addRule(): void {
    const weekly = this.mode() === 'weekly';
    const rule: ReservedSlotRule = {
      id: `res-${Date.now()}`,
      supplierId: this.supplierId(),
      dayOfWeek: weekly ? this.dayOfWeek() : null,
      date: weekly ? null : this.date(),
      slotStartTime: this.slotStartTime(),
      rampId: this.rampId(),
      validFrom: this.validFrom(),
      validTo: this.validTo() === '' ? null : this.validTo(),
      active: true,
    };
    const errors = validateReservedRule(rule, this.config(), this.rules());
    this.errors.set(errors);
    if (errors.length > 0) {
      return;
    }
    this.rules.update((list) => [...list, rule]);
    this.changed.emit(this.rules());
  }

  protected toggleActive(id: string, active: boolean): void {
    this.rules.update((list) =>
      list.map((r) => (r.id === id ? { ...r, active } : r)),
    );
    this.changed.emit(this.rules());
  }

  protected removeRule(id: string): void {
    this.rules.update((list) => list.filter((r) => r.id !== id));
    this.changed.emit(this.rules());
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

  protected periodLabel(rule: ReservedSlotRule): string {
    return rule.dayOfWeek !== null
      ? `${rule.dayOfWeek}`
      : formatDate(rule.date);
  }
}
