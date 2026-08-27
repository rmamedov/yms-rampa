import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
  signal,
} from '@angular/core';
import { TranslatePipe } from '../../core/i18n/translate.pipe';

export interface SelectOption {
  readonly value: string;
  readonly label: string;
}

/** Мультивибір зі списку (фільтри місто / магазин / постачальник). */
@Component({
  selector: 'app-multi-select',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <div class="multi-select">
      <div class="field-label">{{ label() }}</div>
      <button
        type="button"
        class="btn multi-select-trigger"
        (click)="open.set(!open())"
      >
        <span>{{ summary() }}</span>
        <span class="muted">▾</span>
      </button>
      @if (open()) {
        <div class="multi-select-panel">
          @if (searchable()) {
            <input
              type="search"
              [value]="term()"
              (input)="term.set($any($event.target).value)"
              [placeholder]="'common.search' | t"
            />
          }
          <div class="multi-select-list">
            @for (option of visibleOptions(); track option.value) {
              <label class="checkbox">
                <input
                  type="checkbox"
                  [checked]="selected().includes(option.value)"
                  (change)="toggle(option.value)"
                />
                {{ option.label }}
              </label>
            } @empty {
              <span class="muted">{{ 'common.notSet' | t }}</span>
            }
          </div>
          <div class="multi-select-footer">
            <button type="button" class="btn btn-sm" (click)="clear()">
              {{ 'common.reset' | t }}
            </button>
            <button type="button" class="btn btn-sm" (click)="open.set(false)">
              {{ 'common.close' | t }}
            </button>
          </div>
        </div>
      }
    </div>
  `,
})
export class MultiSelectComponent {
  readonly label = input.required<string>();
  readonly options = input.required<readonly SelectOption[]>();
  readonly selected = input.required<readonly string[]>();
  readonly searchable = input(true);
  readonly selectedChange = output<readonly string[]>();

  protected readonly open = signal(false);
  protected readonly term = signal('');

  protected readonly visibleOptions = computed(() => {
    const term = this.term().trim().toLowerCase();
    const options = this.options();
    const filtered =
      term === ''
        ? options
        : options.filter((o) => o.label.toLowerCase().includes(term));
    return filtered.slice(0, 200);
  });

  protected readonly summary = computed(() => {
    const selected = this.selected();
    if (selected.length === 0) {
      return '—';
    }
    const labels = this.options()
      .filter((o) => selected.includes(o.value))
      .map((o) => o.label);
    return labels.length <= 2
      ? labels.join(', ')
      : `${labels[0]}, +${labels.length - 1}`;
  });

  protected toggle(value: string): void {
    const selected = this.selected();
    this.selectedChange.emit(
      selected.includes(value)
        ? selected.filter((v) => v !== value)
        : [...selected, value],
    );
  }

  protected clear(): void {
    this.selectedChange.emit([]);
  }
}
