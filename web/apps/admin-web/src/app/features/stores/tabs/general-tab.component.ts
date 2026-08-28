import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { Store, StoreGeneralPatch, YMS_STATUSES, YmsStatus } from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  validateAddressOverride,
  validateDisplayName,
  validatePhone,
} from '../../../core/utils/validators.util';

/** Зміни секції «Загальне»: стан форми плюс ознака придатності до збереження. */
export interface GeneralChange {
  readonly patch: StoreGeneralPatch;
  readonly invalid: boolean;
}

/**
 * Секція «Загальне»: дані МСР лише для читання + редаговані YMS-поля
 * (STC-01…STC-07).
 *
 * Власної кнопки збереження не має свідомо: сторінка магазину зберігається
 * однією кнопкою внизу, разом із конфігурацією. Тому секція віддає свій стан
 * назовні на КОЖНУ зміну, а не за натисканням.
 */
@Component({
  selector: 'app-store-general-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './general-tab.component.html',
})
export class StoreGeneralTabComponent {
  readonly store = input.required<Store>();
  readonly canEdit = input(false);
  readonly changed = output<GeneralChange>();

  protected readonly displayName = signal('');
  protected readonly phone = signal('');
  protected readonly addressOverride = signal('');
  protected readonly ymsStatus = signal<YmsStatus>('not_configured');
  protected readonly visibleToSuppliers = signal(false);

  /** STL-04: ознаку «налаштовано» і перелік прогалин рахує store-service. */
  protected readonly missing = computed(() => this.store().missingSettings);
  protected readonly isConfigured = computed(() => this.store().isConfigured);
  /** STC-03: перелік дозволених переходів приходить із картки магазину. */
  protected readonly availableStatuses = computed(() => {
    const store = this.store();
    return YMS_STATUSES.filter(
      (s) => s === store.ymsStatus || store.allowedTransitions.includes(s),
    );
  });

  protected readonly displayNameError = computed(() =>
    validateDisplayName(this.displayName()),
  );
  protected readonly phoneError = computed(() =>
    validatePhone(this.phone().trim() === '' ? null : this.phone()),
  );
  protected readonly addressOverrideError = computed(() =>
    validateAddressOverride(
      this.addressOverride().trim() === '' ? null : this.addressOverride(),
    ),
  );
  /** STC-03: активація заблокована для неналаштованого магазину. */
  protected readonly activationBlocked = computed(
    () => this.ymsStatus() === 'active' && !this.isConfigured(),
  );

  protected readonly invalid = computed(
    () =>
      this.displayNameError() !== null ||
      this.phoneError() !== null ||
      this.addressOverrideError() !== null ||
      this.activationBlocked(),
  );

  protected readonly missingLabel = computed(() => this.missing().join(', '));

  constructor() {
    effect(() => {
      const store = this.store();
      // ВСЕ, що нижче, — в untracked. Інакше ефект підписався б на власні
      // поля через buildPatch()/invalid(), і кожне введення користувача
      // перезапускало б його, повертаючи значення з картки: телефон
      // «зберігався» порожнім, бо на сервер їхав уже скинутий стан.
      untracked(() => {
        this.displayName.set(store.displayName ?? '');
        this.phone.set(store.phone ?? '');
        this.addressOverride.set(store.addressOverride ?? '');
        this.ymsStatus.set(store.ymsStatus);
        this.visibleToSuppliers.set(store.visibleToSuppliers);
        // Початковий стан теж піднімаємо: сторінці потрібен повний набір полів,
        // щоб зберегти «Загальне» разом із конфігурацією, навіть якщо тут
        // нічого не чіпали.
        this.changed.emit({ patch: this.buildPatch(), invalid: this.invalid() });
      });
    });
  }

  /** Будь-яка правка одразу піднімається на сторінку — та вирішує, коли зберігати. */
  protected touch(): void {
    this.changed.emit({ patch: this.buildPatch(), invalid: this.invalid() });
  }

  protected buildPatch(): StoreGeneralPatch {
    return {
      displayName: this.displayName().trim() === '' ? null : this.displayName().trim(),
      phone: this.phone().trim() === '' ? null : this.phone().trim(),
      addressOverride:
        this.addressOverride().trim() === '' ? null : this.addressOverride().trim(),
      ymsStatus: this.ymsStatus(),
      // Видимим постачальникам може бути лише активний магазин — це доменне
      // правило бекенду. Раніше форма надсилала прапорець як є, тому спроба
      // поставити магазин на паузу відхилялася цілком: разом зі статусом не
      // зберігалися й усі інші поля.
      visibleToSuppliers: this.ymsStatus() === 'active' ? this.visibleToSuppliers() : false,
    };
  }

  protected setStatus(event: Event): void {
    this.ymsStatus.set((event.target as HTMLSelectElement).value as YmsStatus);
    this.touch();
  }
}
