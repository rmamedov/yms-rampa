import {
  ChangeDetectionStrategy,
  Component,
  inject,
  input,
  output,
} from '@angular/core';
import { I18nService } from '../../core/i18n/i18n.service';

/**
 * Простий bottom-sheet без сторонніх бібліотек.
 * Тач-зони пунктів — не менше 56px (DRV-20).
 */
@Component({
  selector: 'app-bottom-sheet',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="backdrop"
      [attr.aria-label]="closeLabel()"
      (click)="dismiss.emit()"
    ></button>
    <section
      class="sheet"
      role="dialog"
      aria-modal="true"
      [attr.aria-label]="heading()"
    >
      <div class="grabber" aria-hidden="true"></div>
      <h2 class="heading">{{ heading() }}</h2>
      <div class="body">
        <ng-content />
      </div>
    </section>
  `,
  styles: [
    `
      @use 'tokens' as *;
      :host {
        position: fixed;
        inset: 0;
        z-index: 60;
        display: block;
      }
      .backdrop {
        position: absolute;
        inset: 0;
        border: none;
        padding: 0;
        background: rgba(16, 22, 31, 0.45);
      }
      .sheet {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        margin: 0 auto;
        max-width: $content-max;
        background: $color-surface;
        border-radius: $radius-lg $radius-lg 0 0;
        box-shadow: $shadow-sheet;
        padding: $space-3 $space-4 calc(#{$space-5} + env(safe-area-inset-bottom));
      }
      .grabber {
        width: 44px;
        height: 4px;
        border-radius: 2px;
        background: $color-border;
        margin: 0 auto $space-3;
      }
      .heading {
        font-size: $font-size-lg;
        margin-bottom: $space-3;
      }
      .body {
        display: flex;
        flex-direction: column;
        gap: $space-2;
      }
    `,
  ],
})
export class BottomSheetComponent {
  private readonly i18n = inject(I18nService);

  readonly heading = input.required<string>();
  readonly dismiss = output<void>();

  protected closeLabel(): string {
    return this.i18n.t('common.close');
  }
}
