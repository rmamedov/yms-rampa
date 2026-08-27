import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  validateHorizon,
  validateLeadTime,
  validateMaxWeight,
} from '../../../core/utils/validators.util';

export interface LimitsChange {
  readonly maxVehicleWeightTons: number | null;
  readonly leadTimeHours: number;
  readonly bookingHorizonDays: number;
}

/** Вкладка «Обмеження»: maxVehicleWeightTons, lead time, горизонт (STC-30, STC-31). */
@Component({
  selector: 'app-store-limits-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <div class="card">
      <div class="card-title">{{ 'store.tab.limits' | t }}</div>
      <div class="grid-3">
        <div class="field">
          <label for="max-weight">{{ 'limits.maxWeight' | t }}</label>
          <input
            id="max-weight"
            type="number"
            min="1"
            max="40"
            step="0.5"
            [value]="weight() ?? ''"
            [disabled]="!canEdit()"
            (input)="setWeight($any($event.target).value)"
          />
          <div class="field-hint">{{ 'limits.maxWeight.hint' | t }}</div>
          @if (weightError(); as error) {
            <div class="field-error">{{ error | t }}</div>
          }
        </div>

        <div class="field">
          <label for="lead-time">{{ 'limits.leadTime' | t }}</label>
          <input
            id="lead-time"
            type="number"
            min="0"
            max="168"
            step="1"
            [value]="leadTime()"
            [disabled]="!canEdit()"
            (input)="setLeadTime($any($event.target).value)"
          />
          <div class="field-hint">{{ 'limits.leadTime.hint' | t }}</div>
          @if (leadTimeError(); as error) {
            <div class="field-error">{{ error | t }}</div>
          }
        </div>

        <div class="field">
          <label for="horizon">{{ 'limits.horizon' | t }}</label>
          <input
            id="horizon"
            type="number"
            min="1"
            max="90"
            step="1"
            [value]="horizon()"
            [disabled]="!canEdit()"
            (input)="setHorizon($any($event.target).value)"
          />
          @if (horizonError(); as error) {
            <div class="field-error">{{ error | t }}</div>
          }
        </div>
      </div>

      @if (lowered()) {
        <div class="notice notice-warn">{{ 'conflicts.reason.weight_limit' | t }}</div>
      }
    </div>
  `,
})
export class StoreLimitsTabComponent {
  readonly maxVehicleWeightTons = input.required<number | null>();
  readonly leadTimeHours = input.required<number>();
  readonly bookingHorizonDays = input.required<number>();
  readonly canEdit = input(false);
  readonly changed = output<LimitsChange>();

  protected readonly weight = signal<number | null>(null);
  protected readonly leadTime = signal(4);
  protected readonly horizon = signal(14);

  protected readonly weightError = computed(() => validateMaxWeight(this.weight()));
  protected readonly leadTimeError = computed(() => validateLeadTime(this.leadTime()));
  protected readonly horizonError = computed(() => validateHorizon(this.horizon()));

  /** STC-31: зменшення ліміту запускає перевірку конфліктів. */
  protected readonly lowered = computed(() => {
    const original = this.maxVehicleWeightTons();
    const next = this.weight();
    return original !== null && next !== null && next < original;
  });

  constructor() {
    effect(() => {
      this.weight.set(this.maxVehicleWeightTons());
      this.leadTime.set(this.leadTimeHours());
      this.horizon.set(this.bookingHorizonDays());
    });
  }

  protected setWeight(raw: string): void {
    this.weight.set(raw === '' ? null : Number(raw));
    this.emit();
  }

  protected setLeadTime(raw: string): void {
    this.leadTime.set(Number(raw));
    this.emit();
  }

  protected setHorizon(raw: string): void {
    this.horizon.set(Number(raw));
    this.emit();
  }

  private emit(): void {
    this.changed.emit({
      maxVehicleWeightTons: this.weight(),
      leadTimeHours: this.leadTime(),
      bookingHorizonDays: this.horizon(),
    });
  }
}
