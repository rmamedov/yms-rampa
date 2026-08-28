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
import { Observable, of, switchMap } from 'rxjs';
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
import { GeneralChange, StoreGeneralTabComponent } from './tabs/general-tab.component';
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
  configBlockingErrors,
  emptyReceivingWindows,
  minimumEffectiveDate,
  normalizeReceivingWindows,
  validateEffectiveDate,
} from '../../core/utils/store-config.util';
import { formatDate, kyivDate } from '../../core/utils/time.util';

export type StoreTabId =
  | 'general'
  | 'receiving'
  | 'slots'
  | 'limits'
  | 'reserves'
  | 'blocks';

/** Порядок секцій на сторінці; ті самі ключі використовує навігація-якорі. */
const TABS: readonly StoreTabId[] = [
  'general',
  'receiving',
  'slots',
  'limits',
  'reserves',
  'blocks',
];

/**
 * Картка магазину — ОДНА сторінка з однією кнопкою збереження.
 *
 * Раніше налаштування були розкидані вкладками, і кожна мала свою долю:
 * «Загальне» зберігалося власною кнопкою, «Прийом/Слоти/Обмеження» — спільною,
 * але лише на цих трьох вкладках, а «Резерви» і «Блокування» застосовувалися
 * одразу. Через це виглядало, ніби зберегти не можна ніде.
 *
 * Тепер «Загальне» + «Прийом» + «Слоти» + «Обмеження» редагуються разом і
 * зберігаються однією кнопкою: PATCH картки і НОВА версія конфігурації
 * (POST /stores/{id}/configurations, DATA-09) — рівно те, що змінилося.
 *
 * «Резерви» і «Блокування» лишаються на тій самій сторінці окремими секціями
 * зі своїми діями: це не поля форми, а записи, які додають і знімають
 * поштучно, і застосовуються вони негайно. Секції про це прямо попереджають.
 */
@Component({
  selector: 'app-store-detail-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    BreadcrumbsComponent,
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
  /** Чернетка конфігурації змінена — потрібна нова версія. */
  protected readonly configDirty = signal(false);
  /** Поля картки магазину змінені — потрібен PATCH. */
  protected readonly generalDirty = signal(false);
  protected readonly generalPatch = signal<StoreGeneralPatch | null>(null);
  protected readonly generalInvalid = signal(false);
  protected readonly dirty = computed(() => this.configDirty() || this.generalDirty());
  protected readonly saving = signal(false);

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

  /**
   * Дата набуття чинності відкритої версії, якщо вона ще попереду.
   *
   * Екран редагує ОСТАННЮ версію, а нова за STC-60 діє не раніше завтра. Без
   * цієї позначки збережена зміна виглядає так, ніби її застосували негайно,
   * а в магазині сьогодні ще діє попередня версія.
   */
  protected readonly pendingSince = computed<string | null>(() => {
    const config = this.store()?.configuration;
    if (!config) {
      return null;
    }
    const effective = config.effectiveFrom.slice(0, 10);
    return effective > kyivDate() ? effective : null;
  });

  protected readonly formatDate = formatDate;

  /**
   * Усе, що робить чернетку незбережуваною: бракує обовʼязкових полів
   * (requireInt/requireFloat), немає жодної рампи, зламані інтервали прийому.
   */
  protected readonly configErrors = computed<readonly string[]>(() =>
    configBlockingErrors(this.draft()),
  );

  /**
   * Пояснення до заблокованої кнопки.
   *
   * Секції тепер видно одночасно, тож ховати помилки «поточної» вкладки нема
   * від чого: біля поля стоїть та сама причина, а тут — повний перелік того,
   * що заважає зберегти. Без нього сіра кнопка не мала б жодного пояснення,
   * і саме так виглядало, ніби налаштування «заблоковані».
   */
  protected readonly saveErrors = computed<readonly string[]>(() =>
    this.configErrors(),
  );

  /**
   * «Зберегти» активна, лише коли є що зберігати і воно валідне — в ОБОХ
   * половинах сторінки: і картка магазину, і конфігурація.
   */
  protected readonly canSave = computed(
    () =>
      this.canConfigure() &&
      this.dirty() &&
      !this.saving() &&
      !this.generalInvalid() &&
      this.configErrors().length === 0 &&
      this.effectiveDateError() === null,
  );

  protected readonly crumbs = computed<readonly Crumb[]>(() => {
    const store = this.store();
    if (!store) {
      return [{ label: this.i18n.t('stores.title'), link: ['/stores'] }];
    }
    // Третьої ланки більше немає: сторінка одна, вкладок для неї не існує.
    return [
      { label: this.i18n.t('stores.title'), link: ['/stores'] },
      { label: `${store.city}, філія ${store.externalId}` },
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
    this.configDirty.set(false);
    this.generalDirty.set(false);
    // Секція «Загальне» перезаповниться зі свого effect і сама віддасть стан;
    // скидаємо, щоб порівняння «перший емiт vs правка» почалося заново.
    this.generalPatch.set(null);
    this.effectiveFrom.set(minimumEffectiveDate(store.configuration === null));
  }

  /** Навігація-якорі: секції всі на сторінці, тому просто прокручуємо до неї. */
  protected scrollToSection(tab: StoreTabId, event: Event): void {
    event.preventDefault();
    this.activeTab.set(tab);
    document
      .getElementById(`section-${tab}`)
      ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /**
   * Скасувати незбережені правки.
   *
   * Замінює колишню модалку «вийти без збереження»: вона з'являлася лише при
   * перемиканні вкладок, а вкладок більше немає. Явна кнопка чесніша —
   * користувач сам вирішує, коли відкотитися.
   */
  protected discardChanges(): void {
    const store = this.store();
    if (store) {
      this.applyStore(store);
    }
  }

  /** Секція «Загальне» піднімає свій стан на кожну правку. */
  protected onGeneralChange(change: GeneralChange): void {
    const previous = this.generalPatch();
    this.generalPatch.set(change.patch);
    this.generalInvalid.set(change.invalid);
    // Перший емiт після завантаження лише наповнює стан — це ще не правка
    // користувача, інакше кнопка «Зберегти» світилася б на щойно відкритій
    // сторінці, де ніхто нічого не міняв.
    if (previous !== null && !samePatch(previous, change.patch)) {
      this.generalDirty.set(true);
    }
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
    this.configDirty.set(true);
  }

  protected setEffectiveFrom(value: string): void {
    this.effectiveFrom.set(value);
  }

  protected minEffectiveDate(): string {
    return minimumEffectiveDate(this.isFirstVersion());
  }

  /**
   * Одна кнопка — обидві половини сторінки.
   *
   * Надсилаємо рівно те, що змінилося: зайвий PATCH зайвий раз штовхав би
   * статус магазину, а зайва версія конфігурації засмічувала б історію, яку
   * читає аналітика. Спершу картка, потім конфігурація: якщо друга впаде на
   * валідації, перша вже збережена і користувач не втратить обидві правки.
   */
  protected save(): void {
    const store = this.store();
    if (!store || this.saving() || !this.canSave()) {
      return;
    }

    const general = this.generalDirty() ? this.generalPatch() : null;
    const config = this.configDirty() ? this.draft() : null;

    this.saving.set(true);

    // Явний тип: інакше гілки дають union із двох Observable, і .pipe()
    // не може вибрати перевантаження.
    const generalStep: Observable<Store | null> = general
      ? this.api.updateGeneral(store.id, general)
      : of(null);

    generalStep
      .pipe(
        switchMap(() => {
          if (!config || config.slotSizeMinutes === null || config.maxVehicleWeightTons === null) {
            return of(null);
          }
          return this.api.createConfiguration(store.id, {
            effectiveFrom: this.effectiveFrom(),
            slotSizeMinutes: config.slotSizeMinutes,
            maxVehicleWeightTons: config.maxVehicleWeightTons,
            receivingWindows: config.receivingWindows,
            ramps: config.ramps,
            calendarExceptions: config.calendarExceptions,
            leadTimeMinutes: config.leadTimeMinutes,
            bookingHorizonDays: config.bookingHorizonDays,
            noShowGraceMinutes: config.noShowGraceMinutes,
            holdMaxMinutes: config.holdMaxMinutes,
          });
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.toast.success('conflicts.saved');
          this.reload();
        },
        error: (error: unknown) => {
          this.saving.set(false);
          this.toast.error(error);
          // Картка могла зберегтися, а конфігурація — ні. Перечитуємо, щоб на
          // екрані був справжній стан, а не суміш збереженого й ні.
          this.reload();
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
    // Бекенд віддає лише дні, для яких задано вікна; у формі мають бути всі сім.
    receivingWindows: normalizeReceivingWindows(config.receivingWindows),
    calendarExceptions: config.calendarExceptions.map((e) => ({ ...e })),
  };
}

/**
 * Чи однакові два стани картки магазину.
 *
 * Потрібне, щоб відрізнити «секція щойно віддала початковий стан» від
 * «користувач щось змінив»: інакше кнопка «Зберегти» ставала активною одразу
 * після відкриття сторінки, де ще нічого не чіпали.
 */
function samePatch(a: StoreGeneralPatch, b: StoreGeneralPatch): boolean {
  return (
    a.displayName === b.displayName &&
    a.phone === b.phone &&
    a.addressOverride === b.addressOverride &&
    a.ymsStatus === b.ymsStatus &&
    a.visibleToSuppliers === b.visibleToSuppliers
  );
}
