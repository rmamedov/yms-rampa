import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { TranslatePipe } from '../core/i18n/translate.pipe';
import { StoreScope } from '../core/models/auth.model';

/**
 * Перемикач філії з пошуком.
 *
 * Раніше тут стояв звичайний `<select>`. Керівник мережі бачить у ньому всі
 * свої філії — а їх бувають сотні; знайти потрібну прокруткою неможливо,
 * особливо з клавіатури і на планшеті.
 *
 * Пошук іде по ТИХ САМИХ частинах, що й підпис: назва, код філії, місто,
 * адреса. Інакше користувач бачить рядок, шукає за словом із нього — і нічого
 * не знаходить.
 */
@Component({
  selector: 'app-store-picker',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="picker" [class.picker--open]="open()">
      <button
        type="button"
        class="select picker__trigger"
        [attr.aria-expanded]="open()"
        aria-haspopup="listbox"
        [attr.aria-label]="'header.selectStore' | t"
        (click)="toggle()"
        (keydown)="onTriggerKey($event)"
      >
        <span class="picker__value">{{ currentLabel() }}</span>
        <span class="picker__caret" aria-hidden="true">▾</span>
      </button>

      @if (open()) {
        <div class="picker__panel panel">
          <input
            #search
            class="input picker__search"
            type="search"
            autocomplete="off"
            [attr.placeholder]="'header.storeSearch' | t"
            [value]="query()"
            (input)="query.set($any($event.target).value)"
            (keydown)="onSearchKey($event)"
          />

          @if (filtered().length === 0) {
            <div class="picker__empty">{{ 'header.storeSearch.empty' | t }}</div>
          } @else {
            <ul class="picker__list" role="listbox">
              @for (store of filtered(); track store.storeId; let i = $index) {
                <li>
                  <button
                    type="button"
                    role="option"
                    class="picker__option"
                    [class.picker__option--active]="i === highlighted()"
                    [attr.aria-selected]="store.storeId === selectedId()"
                    (click)="choose(store.storeId)"
                    (mouseenter)="highlighted.set(i)"
                  >
                    {{ label(store) }}
                  </button>
                </li>
              }
            </ul>
          }
        </div>
      }
    </div>
  `,
})
export class StorePickerComponent {
  readonly stores = input.required<readonly StoreScope[]>();
  readonly selectedId = input<string | null>(null);
  readonly selected = output<string>();

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly searchBox = viewChild<ElementRef<HTMLInputElement>>('search');

  protected readonly open = signal(false);
  protected readonly query = signal('');
  protected readonly highlighted = signal(0);

  protected readonly filtered = computed(() => {
    // Кожне слово запиту має знайтися в рядку філії: так «київ берков» звужує
    // список, а не повертає все, де є хоч одне зі слів.
    const terms = this.query().trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (terms.length === 0) {
      return this.stores();
    }
    return this.stores().filter((store) => {
      const haystack = this.label(store).toLowerCase();
      return terms.every((term) => haystack.includes(term));
    });
  });

  protected readonly currentLabel = computed(() => {
    const current = this.stores().find((s) => s.storeId === this.selectedId());
    return current ? this.label(current) : '';
  });

  constructor() {
    // Фокус у поле пошуку одразу після відкриття: інакше доводиться клацати
    // вдруге, а з клавіатури список узагалі недосяжний.
    effect(() => {
      if (this.open()) {
        queueMicrotask(() => this.searchBox()?.nativeElement.focus());
      }
    });

    // Клік повз панель закриває її. Слухаємо документ, а не хост: клік по
    // сусідньому елементу до хоста не дійде.
    document.addEventListener('pointerdown', (event) => {
      if (!this.open()) {
        return;
      }
      if (!this.host.nativeElement.contains(event.target as Node)) {
        this.close();
      }
    });
  }

  protected label(store: StoreScope): string {
    const parts = [store.displayName];
    if (store.externalId) {
      parts.push(store.externalId);
    }
    if (store.city) {
      parts.push(store.address ? `${store.city}, ${store.address}` : store.city);
    }
    return parts.filter(Boolean).join(' · ');
  }

  protected toggle(): void {
    this.open() ? this.close() : this.openPanel();
  }

  protected choose(storeId: string): void {
    this.close();
    // Перевибір тієї самої філії нічого не міняє — і перезавантажувати дошку
    // заради нього не треба.
    if (storeId !== this.selectedId()) {
      this.selected.emit(storeId);
    }
  }

  protected onTriggerKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      this.openPanel();
    }
  }

  protected onSearchKey(event: KeyboardEvent): void {
    const items = this.filtered();

    switch (event.key) {
      case 'Escape':
        event.preventDefault();
        this.close();
        break;
      case 'ArrowDown':
        event.preventDefault();
        this.highlighted.set(Math.min(this.highlighted() + 1, items.length - 1));
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.highlighted.set(Math.max(this.highlighted() - 1, 0));
        break;
      case 'Enter': {
        event.preventDefault();
        const target = items[this.highlighted()];
        if (target) {
          this.choose(target.storeId);
        }
        break;
      }
      default:
        // Список змінився — підсвітка з попереднього запиту стає безглуздою.
        this.highlighted.set(0);
    }
  }

  private openPanel(): void {
    this.query.set('');
    this.highlighted.set(0);
    this.open.set(true);
  }

  private close(): void {
    this.open.set(false);
  }
}
