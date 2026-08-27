import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import {
  CalendarException,
  Ramp,
  ReceivingWindow,
  SlotSizeMinutes,
  Store,
  StoreGeneralPatch,
  Supplier,
} from '../../core/models';
import {
  ReservedSlotRuleDraft,
  SlotBlockDraft,
  StoresApi,
} from '../../core/data/stores.api';
import { SuppliersApi } from '../../core/data/suppliers.api';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../core/ui/toast.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { I18nService } from '../../core/i18n/i18n.service';
import {
  BreadcrumbsComponent,
  Crumb,
} from '../../shared/ui/breadcrumbs.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import { StoreGeneralTabComponent } from './tabs/general-tab.component';
import {
  ReceivingChange,
  StoreReceivingTabComponent,
} from './tabs/receiving-tab.component';
import { SlotsChange, StoreSlotsTabComponent } from './tabs/slots-tab.component';
import { LimitsChange, StoreLimitsTabComponent } from './tabs/limits-tab.component';
import { StoreReservesTabComponent } from './tabs/reserves-tab.component';
import { StoreBlocksTabComponent } from './tabs/blocks-tab.component';
import {
  CONFIG_DEFAULTS,
  ConfigFormState,
  emptyReceivingWindows,
  minimumEffectiveDate,
  validateEffectiveDate,
} from '../../core/utils/store-config.util';

export type StoreTabId =
  | 'general'
  | 'receiving'
  | 'slots'
  | 'limits'
  | 'reserves'
  | 'blocks';

const TABS: readonly StoreTabId[] = [
  'general',
  'receiving',
  'slots',
  'limits',
  'reserves',
  'blocks',
];

/**
 * Картка магазину. Вкладки «Прийом», «Слоти», «Обмеження» редагують ЧЕРНЕТКУ
 * конфігурації і зберігаються однією новою версією
 * (POST /stores/{id}/configurations, DATA-09).
 * «Резерви» і «Блокування» — окремі ресурси зі своїм CRUD, зберігаються одразу.
 */
@Component({
  selector: 'app-store-detail-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    BreadcrumbsComponent,
    ModalComponent,
    StoreGeneralTabComponent,
    StoreReceivingTabComponent,
    StoreSlotsTabComponent,
    StoreLimitsTabComponent,
    StoreReservesTabComponent,
    StoreBlocksTabComponent,
  ],
  templateUrl: './store-detail.page.html',
})
export class StoreDetailPage {
  private readonly api = inject(StoresApi);
  private readonly suppliersApi = inject(SuppliersApi);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly tabs = TABS;
  protected readonly store = signal<Store | null>(null);
  protected readonly suppliers = signal<readonly Supplier[]>([]);
  protected readonly activeTab = signal<StoreTabId>('general');
  protected readonly draft = signal<ConfigFormState | null>(null);
  protected readonly dirty = signal(false);
  protected readonly saving = signal(false);
  protected readonly pendingTab = signal<StoreTabId | null>(null);

  /** STC-60: перша версія може набрати чинності вже сьогодні. */
  protected readonly isFirstVersion = computed(
    () => this.store()?.configuration === null,
  );
  protected readonly effectiveFrom = signal(minimumEffectiveDate());

  /** ADM-05: конфігурацію редагують лише super_admin і network_manager. */
  protected readonly canConfigure = computed(() => this.auth.canConfigureStores());
  /** store_manager може редагувати лише разові блокування свого магазину. */
  protected readonly canBlock = computed(() =>
    this.auth.canBlockSlots(this.store()?.id ?? null),
  );

  protected readonly effectiveDateError = computed(() =>
    validateEffectiveDate(this.effectiveFrom(), this.isFirstVersion()),
  );

  /** Бекенд вимагає ці два поля обовʼязково (requireInt/requireFloat). */
  protected readonly configIncomplete = computed(() => {
    const draft = this.draft();
    return (
      draft === null ||
      draft.slotSizeMinutes === null ||
      draft.maxVehicleWeightTons === null
    );
  });

  protected readonly crumbs = computed<readonly Crumb[]>(() => {
    const store = this.store();
    const tabLabel = this.i18n.t(`store.tab.${this.activeTab()}`);
    if (!store) {
      return [{ label: this.i18n.t('stores.title'), link: ['/stores'] }];
    }
    return [
      { label: this.i18n.t('stores.title'), link: ['/stores'] },
      {
        label: `${store.city}, філія ${store.externalId}`,
        link: ['/stores', store.id],
      },
      { label: tabLabel },
    ];
  });

  constructor() {
    this.route.paramMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((params) => {
        const id = params.get('id');
        if (id) {
          this.loadStore(id);
        }
      });

    this.suppliersApi
      .all()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (suppliers) => this.suppliers.set(suppliers),
        error: () => this.suppliers.set([]),
      });
  }

  private loadStore(id: string): void {
    this.api
      .get(id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (store) => this.applyStore(store),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  private applyStore(store: Store): void {
    this.store.set(store);
    this.draft.set(toFormState(store));
    this.dirty.set(false);
    this.effectiveFrom.set(minimumEffectiveDate(store.configuration === null));
  }

  /** UI-05: незбережені зміни при спробі покинути вкладку. */
  protected selectTab(tab: StoreTabId): void {
    if (tab === this.activeTab()) {
      return;
    }
    if (this.dirty()) {
      this.pendingTab.set(tab);
      return;
    }
    this.activeTab.set(tab);
  }

  protected confirmLeave(): void {
    const tab = this.pendingTab();
    const store = this.store();
    if (store) {
      this.draft.set(toFormState(store));
    }
    this.dirty.set(false);
    this.pendingTab.set(null);
    if (tab) {
      this.activeTab.set(tab);
    }
  }

  protected cancelLeave(): void {
    this.pendingTab.set(null);
  }

  protected onReceivingChange(change: ReceivingChange): void {
    this.patchDraft({
      receivingWindows: change.receivingWindows as ReceivingWindow[],
      calendarExceptions: change.exceptions as CalendarException[],
    });
  }

  protected onSlotsChange(change: SlotsChange): void {
    this.patchDraft({
      slotSizeMinutes: change.slotSizeMinutes as SlotSizeMinutes | null,
      ramps: change.ramps as Ramp[],
    });
  }

  protected onLimitsChange(change: LimitsChange): void {
    this.patchDraft(change);
  }

  private patchDraft(patch: Partial<ConfigFormState>): void {
    const current = this.draft();
    if (!current) {
      return;
    }
    this.draft.set({ ...current, ...patch });
    this.dirty.set(true);
  }

  protected setEffectiveFrom(value: string): void {
    this.effectiveFrom.set(value);
  }

  protected minEffectiveDate(): string {
    return minimumEffectiveDate(this.isFirstVersion());
  }

  /** DATA-09: збереження створює НОВУ версію конфігурації. */
  protected save(): void {
    const store = this.store();
    const draft = this.draft();
    if (!store || !draft || this.saving()) {
      return;
    }
    if (this.effectiveDateError() !== null) {
      this.toast.errorKey('conflicts.error.effectiveFrom');
      return;
    }
    if (draft.slotSizeMinutes === null || draft.maxVehicleWeightTons === null) {
      this.toast.errorKey('store.error.configIncomplete');
      return;
    }
    this.saving.set(true);
    this.api
      .createConfiguration(store.id, {
        effectiveFrom: this.effectiveFrom(),
        slotSizeMinutes: draft.slotSizeMinutes,
        maxVehicleWeightTons: draft.maxVehicleWeightTons,
        receivingWindows: draft.receivingWindows,
        ramps: draft.ramps,
        calendarExceptions: draft.calendarExceptions,
        leadTimeMinutes: draft.leadTimeMinutes,
        bookingHorizonDays: draft.bookingHorizonDays,
        noShowGraceMinutes: draft.noShowGraceMinutes,
        holdMaxMinutes: draft.holdMaxMinutes,
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.toast.success('conflicts.saved');
          this.reload();
        },
        error: (error: unknown) => {
          this.saving.set(false);
          this.toast.error(error);
        },
      });
  }

  protected saveGeneral(patch: StoreGeneralPatch): void {
    const store = this.store();
    if (!store) {
      return;
    }
    this.saving.set(true);
    this.api
      .updateGeneral(store.id, patch)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (updated) => {
          this.saving.set(false);
          this.applyStore(updated);
          this.toast.success('conflicts.saved');
        },
        error: (error: unknown) => {
          this.saving.set(false);
          this.toast.error(error);
        },
      });
  }

  // --- Резерви (окремий ресурс) ------------------------------------------

  protected createReserve(draft: ReservedSlotRuleDraft): void {
    const store = this.store();
    if (!store) {
      return;
    }
    this.api
      .createReservedRule(store.id, draft)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.toast.success('conflicts.saved');
          this.reload();
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected toggleReserve(event: { id: string; active: boolean }): void {
    const store = this.store();
    if (!store) {
      return;
    }
    this.api
      .updateReservedRule(store.id, event.id, { active: event.active })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => this.reload(),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  protected removeReserve(id: string): void {
    const store = this.store();
    if (!store) {
      return;
    }
    this.api
      .deleteReservedRule(store.id, id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => this.reload(),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  // --- Блокування слотів (окремий ресурс) --------------------------------

  protected createBlock(draft: SlotBlockDraft): void {
    const store = this.store();
    if (!store) {
      return;
    }
    this.api
      .createSlotBlock(store.id, draft)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.toast.success('conflicts.saved');
          this.reload();
        },
        error: (error: unknown) => this.toast.error(error),
      });
  }

  /** STC-52: дострокове зняття блокування. */
  protected releaseBlock(id: string): void {
    const store = this.store();
    if (!store) {
      return;
    }
    this.api
      .releaseSlotBlock(store.id, id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => this.reload(),
        error: (error: unknown) => this.toast.error(error),
      });
  }

  private reload(): void {
    const store = this.store();
    if (store) {
      this.loadStore(store.id);
    }
  }
}

export function toFormState(store: Store): ConfigFormState {
  const config = store.configuration;
  if (!config) {
    return {
      slotSizeMinutes: null,
      ramps: [],
      maxVehicleWeightTons: null,
      leadTimeMinutes: CONFIG_DEFAULTS.leadTimeMinutes,
      bookingHorizonDays: CONFIG_DEFAULTS.bookingHorizonDays,
      noShowGraceMinutes: CONFIG_DEFAULTS.noShowGraceMinutes,
      holdMaxMinutes: CONFIG_DEFAULTS.holdMaxMinutes,
      receivingWindows: emptyReceivingWindows(),
      calendarExceptions: [],
    };
  }
  return {
    slotSizeMinutes: config.slotSizeMinutes,
    ramps: config.ramps.map((r) => ({ ...r })),
    maxVehicleWeightTons: config.maxVehicleWeightTons,
    leadTimeMinutes: config.leadTimeMinutes,
    bookingHorizonDays: config.bookingHorizonDays,
    noShowGraceMinutes: config.noShowGraceMinutes,
    holdMaxMinutes: config.holdMaxMinutes,
    receivingWindows: config.receivingWindows.map((w) => ({
      dayOfWeek: w.dayOfWeek,
      intervals: w.intervals.map((i) => ({ ...i })),
    })),
    calendarExceptions: config.calendarExceptions.map((e) => ({ ...e })),
  };
}
