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
  ConfigConflict,
  ConflictDecision,
  Ramp,
  ReceivingWindow,
  ReservedSlotRule,
  SlotBlock,
  SlotSizeMinutes,
  Store,
  StoreConfig,
  StoreGeneralPatch,
  Supplier,
} from '../../core/models';
import { StoresApi } from '../../core/data/stores.api';
import { SuppliersApi } from '../../core/data/suppliers.api';
import { AuditApi } from '../../core/data/audit.api';
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
import { ConflictsDialogComponent } from './conflicts-dialog.component';
import {
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
    ConflictsDialogComponent,
  ],
  templateUrl: './store-detail.page.html',
})
export class StoreDetailPage {
  private readonly api = inject(StoresApi);
  private readonly suppliersApi = inject(SuppliersApi);
  private readonly auditApi = inject(AuditApi);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthService);

  protected readonly tabs = TABS;
  protected readonly store = signal<Store | null>(null);
  protected readonly suppliers = signal<readonly Supplier[]>([]);
  protected readonly activeTab = signal<StoreTabId>('general');
  protected readonly draft = signal<StoreConfig | null>(null);
  protected readonly dirty = signal(false);
  protected readonly saving = signal(false);

  protected readonly effectiveFrom = signal(minimumEffectiveDate());
  protected readonly conflicts = signal<readonly ConfigConflict[] | null>(null);
  protected readonly pendingTab = signal<StoreTabId | null>(null);

  /** ADM-05: конфігурацію редагують лише super_admin і network_manager. */
  protected readonly canConfigure = computed(() => this.auth.canConfigureStores());
  /** store_manager може редагувати лише разові блокування свого магазину (5.3.6). */
  protected readonly canBlock = computed(() =>
    this.auth.canBlockSlots(this.store()?.id ?? null),
  );

  protected readonly effectiveDateError = computed(() =>
    validateEffectiveDate(this.effectiveFrom()),
  );

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
        next: (store) => {
          this.store.set(store);
          this.draft.set(toConfig(store));
          this.dirty.set(false);
        },
        error: (error: unknown) => this.toast.error(error),
      });
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
      this.draft.set(toConfig(store));
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
      exceptions: change.exceptions as CalendarException[],
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

  protected onReservesChange(rules: readonly ReservedSlotRule[]): void {
    this.patchDraft({ reservedRules: rules });
  }

  protected onBlocksChange(blocks: readonly SlotBlock[]): void {
    this.patchDraft({ slotBlocks: blocks });
  }

  private patchDraft(patch: Partial<StoreConfig>): void {
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
    return minimumEffectiveDate();
  }

  /** STC-62: перед збереженням — перевірка конфліктів. */
  protected requestSave(): void {
    const store = this.store();
    const draft = this.draft();
    if (!store || !draft || this.saving()) {
      return;
    }
    if (this.effectiveDateError() !== null) {
      this.toast.errorKey('conflicts.error.effectiveFrom');
      return;
    }
    this.saving.set(true);
    this.api
      .checkConflicts(store.id, draft, this.effectiveFrom())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (conflicts) => {
          this.saving.set(false);
          if (conflicts.length === 0) {
            this.persist([]);
          } else {
            this.conflicts.set(conflicts);
          }
        },
        error: (error: unknown) => {
          this.saving.set(false);
          this.toast.error(error);
        },
      });
  }

  protected onConflictsConfirmed(decisions: readonly ConflictDecision[]): void {
    this.conflicts.set(null);
    this.persist(decisions);
  }

  protected closeConflicts(): void {
    this.conflicts.set(null);
  }

  private persist(decisions: readonly ConflictDecision[]): void {
    const store = this.store();
    const draft = this.draft();
    if (!store || !draft) {
      return;
    }
    this.saving.set(true);
    this.api
      .saveConfig({
        storeId: store.id,
        effectiveFrom: this.effectiveFrom(),
        config: draft,
        decisions,
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (updated) => {
          this.saving.set(false);
          this.store.set(updated);
          this.draft.set(toConfig(updated));
          this.dirty.set(false);
          this.toast.success('conflicts.saved');
          this.writeAudit(updated, 'update');
          for (const decision of decisions) {
            if (decision.resolution === 'cancel_notify') {
              this.writeAudit(updated, 'conflict_resolve');
            }
          }
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
          this.store.set(updated);
          this.dirty.set(false);
          this.toast.success('conflicts.saved');
          this.writeAudit(updated, 'status_change', [
            {
              field: 'ymsStatus',
              oldValue: store.ymsStatus,
              newValue: updated.ymsStatus,
            },
          ]);
        },
        error: (error: unknown) => {
          this.saving.set(false);
          this.toast.error(error);
        },
      });
  }

  private writeAudit(
    store: Store,
    action: 'update' | 'status_change' | 'conflict_resolve',
    changes: { field: string; oldValue: string | null; newValue: string | null }[] = [],
  ): void {
    this.auditApi
      .write({
        objectType: 'store',
        objectId: store.id,
        objectLabel: `${store.externalId} — ${store.city}`,
        action,
        changes,
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
  }
}

function toConfig(store: Store): StoreConfig {
  return {
    slotSizeMinutes: store.slotSizeMinutes,
    ramps: store.ramps.map((r) => ({ ...r })),
    maxVehicleWeightTons: store.maxVehicleWeightTons,
    leadTimeHours: store.leadTimeHours,
    bookingHorizonDays: store.bookingHorizonDays,
    receivingWindows: store.receivingWindows.map((w) => ({
      dayOfWeek: w.dayOfWeek,
      intervals: w.intervals.map((i) => ({ ...i })),
    })),
    exceptions: store.exceptions.map((e) => ({ ...e })),
    reservedRules: store.reservedRules.map((r) => ({ ...r })),
    slotBlocks: store.slotBlocks.map((b) => ({ ...b })),
  };
}
