import {
  ChangeDetectionStrategy,
  Component,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import type { DelayPayload } from '../../core/models/route-sheet.model';
import {
  kyivLocalInputToIso,
  toKyivLocalInputValue,
} from '../../core/util/time.util';

/** Довідник типових причин затримки (DRV-41). */
export const DELAY_REASON_KEYS = [
  'delay.reason.traffic',
  'delay.reason.breakdown',
  'delay.reason.loading',
  'delay.reason.weather',
  'delay.reason.other',
] as const;

@Component({
  selector: 'app-delay-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <form class="form" (submit)="submit($event)" novalidate>
      <label class="field">
        <span class="label">{{ 'delay.eta' | t }}</span>
        <input
          class="input"
          type="datetime-local"
          [value]="eta()"
          (input)="onEtaInput($event)"
        />
      </label>

      <div class="reasons" role="group" [attr.aria-label]="'delay.reason' | t">
        @for (key of reasonKeys; track key) {
          <button
            type="button"
            class="chip"
            [class.selected]="selectedReasonKey() === key"
            (click)="pickReason(key)"
          >
            {{ key | t }}
          </button>
        }
      </div>

      <label class="field">
        <span class="label">{{ 'delay.reason' | t }}</span>
        <input
          class="input"
          type="text"
          maxlength="120"
          [value]="reason()"
          [placeholder]="'delay.reasonPlaceholder' | t"
          (input)="onReasonInput($event)"
        />
      </label>

      @if (error(); as message) {
        <p class="alert" role="alert">{{ message }}</p>
      }

      <button class="btn primary" type="submit" [disabled]="busy()">
        {{ 'delay.submit' | t }}
      </button>
      <button class="btn ghost" type="button" (click)="cancelled.emit()">
        {{ 'delay.cancel' | t }}
      </button>
    </form>
  `,
  styles: [
    `
      @use 'tokens' as *;
      .form {
        display: flex;
        flex-direction: column;
        gap: $space-3;
      }
      .field {
        display: flex;
        flex-direction: column;
        gap: $space-1;
      }
      .label {
        font-size: $font-size-sm;
        font-weight: 600;
        color: $color-text-muted;
      }
      .input {
        min-height: $touch-primary;
        padding: 0 $space-3;
        border: 1px solid $color-border;
        border-radius: $radius-md;
        font-size: $font-size-md;
        background: $color-surface;
      }
      .reasons {
        display: flex;
        flex-wrap: wrap;
        gap: $space-2;
      }
      .chip {
        min-height: $touch-min;
        padding: 0 $space-3;
        border-radius: 999px;
        border: 1px solid $color-border;
        background: $color-surface;
        font-size: $font-size-sm;
      }
      .chip.selected {
        background: $color-primary-soft;
        border-color: $color-primary;
        color: $color-primary-dark;
        font-weight: 600;
      }
      .alert {
        background: $color-danger-soft;
        color: $color-danger;
        border-radius: $radius-md;
        padding: $space-3;
        font-size: $font-size-sm;
      }
      .btn {
        min-height: $touch-primary;
        border-radius: $radius-md;
        border: 1px solid transparent;
        font-weight: 700;
        font-size: $font-size-md;
      }
      .primary {
        background: $color-primary;
        color: $color-text-inverse;
      }
      .ghost {
        background: $color-surface;
        color: $color-text-muted;
        border-color: $color-border;
      }
    `,
  ],
})
export class DelayFormComponent {
  private readonly i18n = inject(I18nService);

  readonly busy = input(false);
  readonly serverError = input<string | null>(null);

  readonly submitted = output<DelayPayload>();
  readonly cancelled = output<void>();

  protected readonly reasonKeys = DELAY_REASON_KEYS;
  protected readonly eta = signal(toKyivLocalInputValue(Date.now() + 30 * 60_000));
  protected readonly reason = signal('');
  protected readonly selectedReasonKey = signal<string | null>(null);
  protected readonly localError = signal<string | null>(null);

  protected error(): string | null {
    return this.localError() ?? this.serverError();
  }

  protected onEtaInput(event: Event): void {
    this.eta.set((event.target as HTMLInputElement).value);
    this.localError.set(null);
  }

  protected onReasonInput(event: Event): void {
    this.reason.set((event.target as HTMLInputElement).value);
    this.selectedReasonKey.set(null);
    this.localError.set(null);
  }

  protected pickReason(key: string): void {
    this.selectedReasonKey.set(key);
    this.reason.set(this.i18n.t(key));
    this.localError.set(null);
  }

  protected submit(event: Event): void {
    event.preventDefault();
    const iso = kyivLocalInputToIso(this.eta());
    if (!iso) {
      this.localError.set(this.i18n.t('delay.error.etaRequired'));
      return;
    }
    if (Date.parse(iso) <= Date.now()) {
      this.localError.set(this.i18n.t('delay.error.etaPast'));
      return;
    }
    const reason = this.reason().trim();
    if (!reason) {
      this.localError.set(this.i18n.t('delay.error.reasonRequired'));
      return;
    }
    this.localError.set(null);
    this.submitted.emit({ eta: iso, reason });
  }
}
