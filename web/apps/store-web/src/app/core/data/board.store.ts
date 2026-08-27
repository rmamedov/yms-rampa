import {
  computed,
  inject,
  Injectable,
  OnDestroy,
  signal,
} from '@angular/core';
import { environment } from '../../../environments/environment';
import { describeError } from '../api/problem.util';
import { AuthService } from '../auth/auth.service';
import { TokenStorageService } from '../auth/token-storage.service';
import {
  Booking,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
  WalkInPayload,
} from '../models/booking.model';
import { Ramp, StoreConfig, SupplierRef } from '../models/store.model';
import {
  ActionContext,
  BookingActionId,
  evaluateAction,
} from '../util/booking-rules.util';
import {
  BoardFilters,
  DailyStats,
  EMPTY_FILTERS,
  EMPTY_RISK,
  RampColumn,
  activeFilterCount,
  applyFilters,
  computeDailyStats,
  computeRiskState,
  computeTimelineBounds,
  groupByRamp,
  sortBySlotStart,
} from '../util/board.util';
import {
  addDaysToDateKey,
  toKyivDateKey,
} from '../util/date.util';
import { StoreGateway } from './gateways';

export type BoardViewMode = 'board' | 'timeline';

export interface Toast {
  readonly tone: 'success' | 'error';
  readonly key: string | null;
  readonly text: string | null;
  readonly params?: Record<string, string | number>;
}

/**
 * Центральний стан дошки «Сьогодні»: дані, фільтри, статистика, realtime,
 * дії над бронюваннями.
 */
@Injectable({ providedIn: 'root' })
export class BoardStore implements OnDestroy {
  private readonly gateway = inject(StoreGateway);
  private readonly auth = inject(AuthService);
  private readonly storage = inject(TokenStorageService);

  private readonly tick = signal(Date.now());
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private clockTimer: ReturnType<typeof setInterval> | null = null;
  private toastTimer: ReturnType<typeof setTimeout> | null = null;

  // --- Сирий стан -------------------------------------------------------
  private readonly bookingsSignal = signal<readonly Booking[]>([]);
  private readonly configSignal = signal<StoreConfig | null>(null);
  private readonly suppliersSignal = signal<readonly SupplierRef[]>([]);
  private readonly loadingSignal = signal(false);
  private readonly busySignal = signal<string | null>(null);
  private readonly lastSyncSignal = signal<number | null>(null);
  private readonly toastSignal = signal<Toast | null>(null);
  private readonly viewDateSignal = signal(toKyivDateKey(new Date()));
  private readonly viewModeSignal = signal<BoardViewMode>(
    (this.storage.getViewMode() as BoardViewMode | null) ?? 'board',
  );
  private readonly filtersSignal = signal<BoardFilters>(EMPTY_FILTERS);

  // --- Публічні читальні сигнали ---------------------------------------
  readonly bookings = this.bookingsSignal.asReadonly();
  readonly config = this.configSignal.asReadonly();
  readonly suppliers = this.suppliersSignal.asReadonly();
  readonly loading = this.loadingSignal.asReadonly();
  readonly busyBookingId = this.busySignal.asReadonly();
  readonly toast = this.toastSignal.asReadonly();
  readonly viewDateKey = this.viewDateSignal.asReadonly();
  readonly viewMode = this.viewModeSignal.asReadonly();
  readonly filters = this.filtersSignal.asReadonly();
  readonly lastSyncAt = this.lastSyncSignal.asReadonly();

  readonly nowIso = computed(() => new Date(this.tick()).toISOString());
  readonly todayKey = computed(() => toKyivDateKey(new Date(this.tick())));
  readonly isToday = computed(() => this.viewDateSignal() === this.todayKey());
  readonly isPastDate = computed(() => this.viewDateSignal() < this.todayKey());

  readonly ramps = computed<readonly Ramp[]>(
    () => this.configSignal()?.ramps ?? [],
  );

  readonly filteredBookings = computed(() =>
    applyFilters(this.bookingsSignal(), this.filtersSignal()),
  );

  readonly activeFilters = computed(() => activeFilterCount(this.filtersSignal()));

  /** Ризики рахуються по всьому дню, а не по відфільтрованому зрізу. */
  readonly risk = computed(() =>
    this.isToday()
      ? computeRiskState(this.bookingsSignal(), this.nowIso())
      : EMPTY_RISK,
  );

  readonly stats = computed<DailyStats>(() =>
    computeDailyStats(this.bookingsSignal()),
  );

  readonly rampColumns = computed<readonly RampColumn[]>(() =>
    groupByRamp(this.filteredBookings(), this.ramps(), this.risk()),
  );

  readonly listView = computed(() => sortBySlotStart(this.filteredBookings()));

  readonly timelineBounds = computed(() => {
    const config = this.configSignal();
    if (!config) return computeTimelineBounds([]);
    const dow = isoDow(this.viewDateSignal());
    const window = config.receivingWindows.find((w) => w.dayOfWeek === dow);
    return computeTimelineBounds(window?.intervals ?? []);
  });

  /** STW-12: банер неактуальних даних. */
  readonly isStale = computed(() => {
    const last = this.lastSyncSignal();
    if (last === null) return false;
    return this.tick() - last > environment.staleThresholdMs;
  });

  readonly supplierNames = computed(() => {
    const names = new Set<string>();
    for (const b of this.bookingsSignal()) names.add(b.supplierName);
    return [...names].sort((a, b) => a.localeCompare(b, 'uk-UA'));
  });

  constructor() {
    this.clockTimer = setInterval(() => this.tick.set(Date.now()), 5_000);
  }

  ngOnDestroy(): void {
    this.stopPolling();
    if (this.clockTimer) clearInterval(this.clockTimer);
    if (this.toastTimer) clearTimeout(this.toastTimer);
  }

  // --- Завантаження -----------------------------------------------------

  /**
   * Повне перезавантаження контексту магазину (STW-04).
   *
   * Спершу — перелік доступних філій: без нього мережева роль не має обраного
   * магазину, і завантажувати не було б чого.
   */
  load(): void {
    this.loadingSignal.set(true);
    this.auth.ensureStores().subscribe({
      next: () => this.loadSelectedStore(),
      error: (error: unknown) => {
        this.loadingSignal.set(false);
        this.showError(error);
      },
    });
  }

  private loadSelectedStore(): void {
    const store = this.auth.selectedStore();
    if (!store) {
      this.loadingSignal.set(false);
      return;
    }
    this.gateway.getStoreConfig(store.storeId).subscribe({
      next: (config) => {
        this.configSignal.set(config);
        this.auth.describeStore(config);
        this.reload();
      },
      error: (error: unknown) => {
        this.loadingSignal.set(false);
        this.showError(error);
      },
    });
    // Довідник постачальників маршрут віддає цілком — беремо як є, без зрізів.
    this.gateway.getSuppliers(store.storeId).subscribe({
      next: (suppliers) => this.suppliersSignal.set(suppliers),
      error: () => this.suppliersSignal.set([]),
    });
  }

  /** Перечитує дошку поточної дати. */
  reload(silent = false): void {
    const store = this.auth.selectedStore();
    if (!store) return;
    if (!silent) this.loadingSignal.set(true);
    this.gateway.getBoard(store.storeId, this.viewDateSignal()).subscribe({
      next: (snapshot) => {
        this.bookingsSignal.set(snapshot.bookings);
        this.lastSyncSignal.set(Date.now());
        this.tick.set(Date.now());
        this.loadingSignal.set(false);
      },
      error: (error: unknown) => {
        this.loadingSignal.set(false);
        if (!silent) this.showError(error);
      },
    });
  }

  /** RT-04: fallback-полінг раз на 15 с. */
  startPolling(): void {
    this.stopPolling();
    this.pollTimer = setInterval(
      () => this.reload(true),
      environment.pollingIntervalMs,
    );
  }

  stopPolling(): void {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  // --- Керування виглядом ----------------------------------------------

  setDate(dateKey: string): void {
    this.viewDateSignal.set(dateKey);
    this.reload();
  }

  shiftDate(days: number): void {
    this.setDate(addDaysToDateKey(this.viewDateSignal(), days));
  }

  goToday(): void {
    this.setDate(this.todayKey());
  }

  setViewMode(mode: BoardViewMode): void {
    this.viewModeSignal.set(mode);
    this.storage.setViewMode(mode);
  }

  setFilters(filters: BoardFilters): void {
    this.filtersSignal.set(filters);
  }

  clearFilters(): void {
    this.filtersSignal.set(EMPTY_FILTERS);
  }

  // --- Контекст дій -----------------------------------------------------

  actionContext(booking: Booking): ActionContext {
    return {
      now: this.nowIso(),
      viewDateKey: this.viewDateSignal(),
      todayKey: this.todayKey(),
      role: this.auth.profile()?.role ?? 'store_operator',
      hasFreeRampForReassign: this.freeRampsFor(booking).length > 0,
    };
  }

  can(booking: Booking, action: BookingActionId): boolean {
    return evaluateAction(booking, action, this.actionContext(booking)).enabled;
  }

  reasonKey(booking: Booking, action: BookingActionId): string | null {
    return evaluateAction(booking, action, this.actionContext(booking)).reasonKey;
  }

  /** Рампи з вільним слотом у той самий час (STW-41). */
  freeRampsFor(booking: Booking): Ramp[] {
    const occupied = new Set(
      this.bookingsSignal()
        .filter(
          (b) =>
            b.slotStart === booking.slotStart &&
            b.id !== booking.id &&
            b.status !== 'cancelled' &&
            b.status !== 'no_show' &&
            b.status !== 'rejected',
        )
        .map((b) => b.rampId),
    );
    return this.ramps().filter(
      (ramp) =>
        ramp.active && ramp.rampId !== booking.rampId && !occupied.has(ramp.rampId),
    );
  }

  // --- Дії --------------------------------------------------------------

  /** ST-01: booked → arrived (магазин фіксує прибуття замість водія). */
  markArrived(booking: Booking): void {
    this.run(booking, (b) => this.gateway.markArrived(b.id));
  }

  startUnloading(booking: Booking): void {
    this.run(booking, (b) => this.gateway.startUnloading(b.id));
  }

  complete(booking: Booking, payload: CompleteUnloadingPayload): void {
    this.run(
      booking,
      (b) => this.gateway.completeUnloading(b.id, payload),
      'status.completed',
    );
  }

  markNoShow(booking: Booking): void {
    this.run(booking, (b) => this.gateway.markNoShow(b.id));
  }

  reject(booking: Booking, payload: RejectPayload): void {
    this.run(booking, (b) => this.gateway.reject(b.id, payload));
  }

  setDelay(booking: Booking, payload: DelayPayload): void {
    this.run(booking, (b) => this.gateway.setDelay(b.id, payload));
  }

  reassign(booking: Booking, payload: ReassignPayload): void {
    const name =
      this.ramps().find((r) => r.rampId === payload.rampId)?.name ?? payload.rampId;
    this.run(
      booking,
      (b) => this.gateway.reassignRamp(b.id, payload),
      'reassign.done',
      { name },
    );
  }

  createWalkIn(
    payload: Omit<WalkInPayload, 'storeId'>,
    onSuccess?: () => void,
  ): void {
    const store = this.auth.selectedStore();
    if (!store) return;
    this.busySignal.set('walk-in');
    this.gateway.createWalkIn({ ...payload, storeId: store.storeId }).subscribe({
      next: (booking) => {
        this.busySignal.set(null);
        this.bookingsSignal.update((list) => [...list, booking]);
        this.lastSyncSignal.set(Date.now());
        this.showToast({ tone: 'success', key: 'walkIn.created', text: null });
        onSuccess?.();
      },
      error: (error: unknown) => {
        this.busySignal.set(null);
        this.showError(error);
      },
    });
  }

  dismissToast(): void {
    this.toastSignal.set(null);
  }

  /** Показує повідомлення і ховає його автоматично. */
  private showToast(toast: Toast): void {
    this.toastSignal.set(toast);
    if (this.toastTimer) clearTimeout(this.toastTimer);
    this.toastTimer = setTimeout(() => this.toastSignal.set(null), 6_000);
  }

  private run(
    booking: Booking,
    call: (booking: Booking) => import('rxjs').Observable<Booking>,
    successKey?: string,
    successParams?: Record<string, string | number>,
  ): void {
    this.busySignal.set(booking.id);
    call(booking).subscribe({
      next: (updated) => {
        this.busySignal.set(null);
        this.upsert(updated);
        if (successKey) {
          this.showToast({
            tone: 'success',
            key: successKey,
            text: null,
            params: successParams,
          });
        }
      },
      error: (error: unknown) => {
        this.busySignal.set(null);
        this.showError(error);
        // STW-17: після 409 INVALID_STATUS_TRANSITION картку треба показати
        // в актуальному стані — оптимістичної версії бекенд не має.
        this.reload(true);
      },
    });
  }

  private upsert(booking: Booking): void {
    this.bookingsSignal.update((list) => {
      const index = list.findIndex((b) => b.id === booking.id);
      if (index < 0) return [...list, booking];
      const next = [...list];
      next[index] = booking;
      return next;
    });
    this.lastSyncSignal.set(Date.now());
  }

  private showError(error: unknown): void {
    const described = describeError(error);
    this.showToast({
      tone: 'error',
      key: described.key,
      text: described.text,
    });
  }
}

function isoDow(dateKey: string): number {
  const [y, m, d] = dateKey.split('-').map(Number);
  const dow = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return dow === 0 ? 7 : dow;
}
