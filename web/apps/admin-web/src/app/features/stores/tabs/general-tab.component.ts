import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core';
import { Store, StoreGeneralPatch, YMS_STATUSES, YmsStatus } from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  validateAddressOverride,
  validateDisplayName,
  validatePhone,
} from '../../../core/utils/validators.util';

/** Вкладка «Загальне»: MCP read-only + editable YMS-поля (STC-01…STC-07). */
@Component({
  selector: 'app-store-general-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './general-tab.component.html',
})
export class StoreGeneralTabComponent {
  readonly store = input.required<Store>();
  readonly canEdit = input(false);
  readonly patchChange = output<StoreGeneralPatch>();
  readonly dirtyChange = output<boolean>();

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
      this.displayName.set(store.displayName ?? '');
      this.phone.set(store.phone ?? '');
      this.addressOverride.set(store.addressOverride ?? '');
      this.ymsStatus.set(store.ymsStatus);
      this.visibleToSuppliers.set(store.visibleToSuppliers);
    });
  }

  protected touch(): void {
    this.dirtyChange.emit(true);
  }

  protected buildPatch(): StoreGeneralPatch {
    return {
      displayName: this.displayName().trim() === '' ? null : this.displayName().trim(),
      phone: this.phone().trim() === '' ? null : this.phone().trim(),
      addressOverride:
        this.addressOverride().trim() === '' ? null : this.addressOverride().trim(),
      ymsStatus: this.ymsStatus(),
      visibleToSuppliers: this.visibleToSuppliers(),
    };
  }

  protected save(): void {
    if (this.invalid()) {
      return;
    }
    this.patchChange.emit(this.buildPatch());
  }

  protected setStatus(event: Event): void {
    this.ymsStatus.set((event.target as HTMLSelectElement).value as YmsStatus);
    this.touch();
  }
}
