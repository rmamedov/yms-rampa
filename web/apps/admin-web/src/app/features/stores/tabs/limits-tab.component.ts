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
  validateHoldMax,
  validateHorizon,
  validateLeadTime,
  validateMaxWeight,
  validateNoShowGrace,
} from '../../../core/utils/validators.util';

/** Поля конфігурації бекенду — усі часові величини у ХВИЛИНАХ. */
export interface LimitsChange {
  readonly maxVehicleWeightTons: number | null;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly noShowGraceMinutes: number;
  readonly holdMaxMinutes: number;
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
          <label for="max-weight">{{ 'limits.maxWeight' | t }}<span class="req" aria-hidden="true">*</span></label>
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
          <label for="lead-time">{{ 'limits.leadTime' | t }}<span class="req" aria-hidden="true">*</span></label>
          <input
            id="lead-time"
            type="number"
            min="0"
            max="1440"
            step="5"
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
          <label for="horizon">{{ 'limits.horizon' | t }}<span class="req" aria-hidden="true">*</span></label>
          <input
            id="horizon"
            type="number"
            min="1"
            max="30"
            step="1"
            [value]="horizon()"
            [disabled]="!canEdit()"
            (input)="setHorizon($any($event.target).value)"
          />
          @if (horizonError(); as error) {
            <div class="field-error">{{ error | t }}</div>
          }
        </div>

        <div class="field">
          <label for="no-show-grace">{{ 'limits.noShowGrace' | t }}<span class="req" aria-hidden="true">*</span></label>
          <input
            id="no-show-grace"
            type="number"
            min="0"
            max="240"
            step="5"
            [value]="noShowGrace()"
            [disabled]="!canEdit()"
            (input)="setNoShowGrace($any($event.target).value)"
          />
          <div class="field-hint">{{ 'limits.noShowGrace.hint' | t }}</div>
          @if (noShowGraceError(); as error) {
            <div class="field-error">{{ error | t }}</div>
          }
        </div>

        <div class="field">
          <label for="hold-max">{{ 'limits.holdMax' | t }}<span class="req" aria-hidden="true">*</span></label>
          <input
            id="hold-max"
            type="number"
            min="1"
            max="60"
            step="1"
            [value]="holdMax()"
            [disabled]="!canEdit()"
            (input)="setHoldMax($any($event.target).value)"
          />
          <div class="field-hint">{{ 'limits.holdMax.hint' | t }}</div>
          @if (holdMaxError(); as error) {
            <div class="field-error">{{ error | t }}</div>
          }
        </div>
      </div>

      @if (lowered()) {
        <div class="notice notice-warn">{{ 'limits.lowered' | t }}</div>
      }
    </div>
  `,
})
export class StoreLimitsTabComponent {
  readonly maxVehicleWeightTons = input.required<number | null>();
  /** Тоннаж чинної збереженої конфігурації — «як було» для попередження STC-31. */
  readonly savedMaxVehicleWeightTons = input.required<number | null>();
  readonly leadTimeMinutes = input.required<number>();
  readonly bookingHorizonDays = input.required<number>();
  readonly noShowGraceMinutes = input.required<number>();
  readonly holdMaxMinutes = input.required<number>();
  readonly canEdit = input(false);
  readonly changed = output<LimitsChange>();

  protected readonly weight = signal<number | null>(null);
  protected readonly leadTime = signal(60);
  protected readonly horizon = signal(14);
  protected readonly noShowGrace = signal(30);
  protected readonly holdMax = signal(15);

  protected readonly weightError = computed(() => validateMaxWeight(this.weight()));
  protected readonly leadTimeError = computed(() => validateLeadTime(this.leadTime()));
  protected readonly horizonError = computed(() => validateHorizon(this.horizon()));
  protected readonly noShowGraceError = computed(() =>
    validateNoShowGrace(this.noShowGrace()),
  );
  protected readonly holdMaxError = computed(() => validateHoldMax(this.holdMax()));

  /**
   * STC-31: зменшення ліміту зачіпає вже наявні бронювання.
   * Еталон «як було» — чинна збережена конфігурація, а не вхідне значення
   * чернетки: батько оновлює чернетку одразу після кожного редагування,
   * тож порівняння з нею завжди давало б хибу.
   */
  protected readonly lowered = computed(() => {
    const saved = this.savedMaxVehicleWeightTons();
    const next = this.weight();
    return saved !== null && next !== null && next < saved;
  });

  constructor() {
    effect(() => {
      this.weight.set(this.maxVehicleWeightTons());
      this.leadTime.set(this.leadTimeMinutes());
      this.horizon.set(this.bookingHorizonDays());
      this.noShowGrace.set(this.noShowGraceMinutes());
      this.holdMax.set(this.holdMaxMinutes());
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

  protected setNoShowGrace(raw: string): void {
    this.noShowGrace.set(Number(raw));
    this.emit();
  }

  protected setHoldMax(raw: string): void {
    this.holdMax.set(Number(raw));
    this.emit();
  }

  private emit(): void {
    this.changed.emit({
      maxVehicleWeightTons: this.weight(),
      leadTimeMinutes: this.leadTime(),
      bookingHorizonDays: this.horizon(),
      noShowGraceMinutes: this.noShowGrace(),
      holdMaxMinutes: this.holdMax(),
    });
  }
}
