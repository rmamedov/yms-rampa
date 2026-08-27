import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';

/** Діалог підтвердження дії (DRV-25). */
@Component({
  selector: 'app-confirm-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="backdrop"
      [attr.aria-label]="cancelLabel()"
      (click)="cancelled.emit()"
    ></button>
    <section class="dialog" role="alertdialog" aria-modal="true">
      <h2 class="heading">{{ heading() }}</h2>
      @if (body()) {
        <p class="body">{{ body() }}</p>
      }
      <button type="button" class="btn primary" (click)="confirmed.emit()">
        {{ confirmLabel() }}
      </button>
      <button type="button" class="btn ghost" (click)="cancelled.emit()">
        {{ cancelLabel() }}
      </button>
    </section>
  `,
  styles: [
    `
      @use 'tokens' as *;
      :host {
        position: fixed;
        inset: 0;
        z-index: 70;
        display: block;
      }
      .backdrop {
        position: absolute;
        inset: 0;
        border: none;
        padding: 0;
        background: rgba(16, 22, 31, 0.5);
      }
      .dialog {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        width: min(420px, calc(100vw - #{$space-5}));
        background: $color-surface;
        border-radius: $radius-lg;
        padding: $space-5 $space-4 $space-4;
        display: flex;
        flex-direction: column;
        gap: $space-3;
      }
      .heading {
        font-size: $font-size-lg;
      }
      .body {
        color: $color-text-muted;
        font-size: $font-size-sm;
      }
      .btn {
        min-height: $touch-primary;
        border-radius: $radius-md;
        border: 1px solid transparent;
        font-weight: 600;
        font-size: $font-size-md;
      }
      .primary {
        background: $color-primary;
        color: $color-text-inverse;
      }
      .ghost {
        background: $color-surface-muted;
        color: $color-text;
        border-color: $color-border;
      }
    `,
  ],
})
export class ConfirmDialogComponent {
  readonly heading = input.required<string>();
  readonly body = input<string | null>(null);
  readonly confirmLabel = input.required<string>();
  readonly cancelLabel = input.required<string>();
  readonly confirmed = output<void>();
  readonly cancelled = output<void>();
}
