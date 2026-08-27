import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  Injector,
  OnInit,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { RouteSheetStore } from '../../core/state/route-sheet.store';
import { AuthService } from '../../core/auth/auth.service';
import { NetworkService } from '../../core/offline/network.service';
import { NavigatorPreferenceService } from '../../core/nav/navigator-preference.service';
import { InstallPromptService } from '../../core/pwa/install-prompt.service';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { PointCardComponent } from './point-card.component';
import { BottomSheetComponent } from '../../shared/ui/bottom-sheet.component';
import type { RoutePoint } from '../../core/models/route-sheet.model';
import {
  DELAY_REASONS,
  DELAY_REASON_LABEL_KEYS,
  DELAY_REASON_REQUIRING_COMMENT,
  type DelayReason,
} from '../../core/models/booking-action.model';
import type { NavigatorApp } from '../../core/util/deep-links';
import { formatPhone } from '../../core/util/phone.util';
import {
  dateChipLabel,
  formatKyivTime,
  kyivDateKey,
  toBackendIso,
} from '../../core/util/time.util';

type SheetKind = 'none' | 'menu' | 'navigator' | 'delay';

/**
 * Наскільки водій затримується. Готові інтервали замість вибору дати:
 * у кабіні це один дотик замість колеса часу, і ETA гарантовано в
 * майбутньому — саме те, чого вимагає Booking::setDelay.
 */
const DELAY_OPTIONS: readonly { minutes: number; labelKey: string }[] = [
  { minutes: 15, labelKey: 'delay.eta15' },
  { minutes: 30, labelKey: 'delay.eta30' },
  { minutes: 60, labelKey: 'delay.eta60' },
  { minutes: 120, labelKey: 'delay.eta120' },
];

const TOAST_MS = 3_000;

@Component({
  selector: 'app-route-sheet-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, PointCardComponent, BottomSheetComponent],
  templateUrl: './route-sheet.page.html',
  styleUrl: './route-sheet.page.scss',
})
export class RouteSheetPage implements OnInit {
  protected readonly store = inject(RouteSheetStore);
  protected readonly network = inject(NetworkService);
  protected readonly install = inject(InstallPromptService);
  private readonly auth = inject(AuthService);
  private readonly navigators = inject(NavigatorPreferenceService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly injector = inject(Injector);
  private scrolledToActive = false;
  private toastTimer: ReturnType<typeof setTimeout> | null = null;

  /** Перемальовує залежні від часу мітки (чипси дат) раз на хвилину. */
  protected readonly nowTick = signal(Date.now());

  protected readonly openSheet = signal<SheetKind>('none');
  protected readonly navigatorPoint = signal<RoutePoint | null>(null);
  protected readonly toast = signal<string | null>(null);

  // --- Форма затримки ---------------------------------------------------------
  protected readonly delayOptions = DELAY_OPTIONS;
  protected readonly delayReasons = DELAY_REASONS;
  protected readonly delayPoint = signal<RoutePoint | null>(null);
  protected readonly delayReason = signal<DelayReason | null>(null);
  protected readonly delayMinutes = signal<number | null>(null);
  protected readonly delayComment = signal('');
  protected readonly delaySubmitting = signal(false);

  /** Коментар обовʼязковий лише для «інше» (DelayReason::requiresComment). */
  protected readonly commentRequired = computed(
    () => this.delayReason() === DELAY_REASON_REQUIRING_COMMENT,
  );

  protected readonly canSubmitDelay = computed(
    () =>
      this.delayReason() !== null &&
      this.delayMinutes() !== null &&
      (!this.commentRequired() || this.delayComment().trim() !== ''),
  );

  /**
   * Імені водія бекенд не віддає — у профілі є лише логін (телефон E.164),
   * тож у шапці показуємо саме його.
   */
  protected readonly driverLabel = computed(() => {
    const profile = this.auth.profile();
    return profile ? formatPhone(profile.login) : null;
  });

  protected readonly dateChips = computed(() =>
    this.store.dates().map((d) => ({
      date: d.date,
      label: dateChipLabel(d.date, this.nowTick(), (key) => this.i18n.t(key)),
      count: d.pointCount,
    })),
  );

  protected readonly showEmptyState = computed(
    () => !this.store.loading() && this.store.points().length === 0,
  );

  protected readonly emptyKey = computed(() =>
    this.store.selectedDate() === kyivDateKey()
      ? 'sheet.empty'
      : 'sheet.emptyOther',
  );

  protected readonly offlineBanner = computed(() => {
    if (!this.store.stale()) {
      return null;
    }
    const at = this.store.cachedAt();
    return this.i18n.t('offline.banner', {
      time: at ? formatKyivTime(at) : '—',
    });
  });

  /** Скільки відміток «На місці» ще не дійшли до сервера. */
  protected readonly queueBanner = computed(() => {
    const count = this.store.queuedArrivals();
    return count > 0 ? this.i18n.t('queue.banner', { count }) : null;
  });

  protected readonly lastSyncLabel = computed(() => {
    const at = this.store.lastSyncAt();
    return at ? this.i18n.t('sheet.updatedAt', { time: formatKyivTime(at) }) : null;
  });

  ngOnInit(): void {
    this.install.init();
    void this.store.initialize();
    // Полінг статусів раз на 30 с (RT-04).
    this.store.startPolling();
    // Активна точка прокручується у видиму область при відкритті листа (DRV-16).
    effect(
      () => {
        const points = this.store.points();
        const index = this.store.activeIndex();
        if (this.scrolledToActive || index < 0 || points.length === 0) {
          return;
        }
        this.scrolledToActive = true;
        const bookingId = points[index].bookingId;
        afterNextRender(
          () => document.getElementById(`point-${bookingId}`)?.scrollIntoView({
            block: 'center',
          }),
          { injector: this.injector },
        );
      },
      { injector: this.injector },
    );
    // Звʼязок відновився — черга відміток «На місці» їде на сервер одразу,
    // не чекаючи наступного тику полінгу.
    effect(
      () => {
        if (this.network.online()) {
          void this.store.flushArrivalQueue();
        }
      },
      { injector: this.injector },
    );
    const tick = setInterval(() => this.nowTick.set(Date.now()), 60_000);
    this.destroyRef.onDestroy(() => {
      clearInterval(tick);
      this.clearToastTimer();
      this.store.stopPolling();
    });
  }

  protected isActive(index: number): boolean {
    return index === this.store.activeIndex();
  }

  protected async selectDate(date: string): Promise<void> {
    this.scrolledToActive = false;
    await this.store.selectDate(date);
  }

  protected async refresh(): Promise<void> {
    this.store.clearActionError();
    await this.store.refresh();
  }

  // --- Дії водія --------------------------------------------------------------

  protected async onArrive(point: RoutePoint): Promise<void> {
    const queuedBefore = this.store.queuedArrivals();
    await this.store.markArrived(point.bookingId);
    // Без звʼязку відмітка лягла в чергу — це не помилка, і водій має
    // отримати підтвердження, а не мовчання.
    if (this.store.queuedArrivals() > queuedBefore) {
      this.showToast(this.i18n.t('point.arriveQueued'));
    }
  }

  protected async onOrderIdSubmitted(event: {
    point: RoutePoint;
    orderId: string | null;
  }): Promise<void> {
    await this.store.updateOrderId(event.point.bookingId, event.orderId);
  }

  // --- Затримка ---------------------------------------------------------------

  protected openDelaySheet(point: RoutePoint): void {
    this.store.clearActionError();
    this.delayPoint.set(point);
    this.delayReason.set(null);
    this.delayMinutes.set(null);
    this.delayComment.set('');
    this.openSheet.set('delay');
  }

  protected reasonLabelKey(reason: DelayReason): string {
    return DELAY_REASON_LABEL_KEYS[reason];
  }

  protected chooseReason(reason: DelayReason): void {
    this.delayReason.set(reason);
  }

  protected chooseDelayMinutes(minutes: number): void {
    this.delayMinutes.set(minutes);
  }

  protected onCommentInput(event: Event): void {
    this.delayComment.set((event.target as HTMLTextAreaElement).value);
  }

  protected async submitDelay(): Promise<void> {
    const point = this.delayPoint();
    const reason = this.delayReason();
    const minutes = this.delayMinutes();

    if (!point || !reason || minutes === null) {
      return;
    }

    this.delaySubmitting.set(true);
    try {
      const ok = await this.store.reportDelay(point.bookingId, {
        reason,
        eta: toBackendIso(Date.now() + minutes * 60_000),
        comment: this.commentRequired() ? this.delayComment().trim() : null,
      });
      if (ok) {
        this.closeSheet();
        this.showToast(this.i18n.t('delay.sent'));
      }
      // Інакше форма лишається відкритою — помилка бекенду вже у store.actionError.
    } finally {
      this.delaySubmitting.set(false);
    }
  }

  // --- Маршрут у навігаторі -------------------------------------------------

  protected onRouteRequested(point: RoutePoint): void {
    const preferred = this.navigators.preferred();
    if (preferred) {
      this.navigators.openRoute(preferred, point);
      return;
    }
    this.openNavigatorSheet(point);
  }

  protected openNavigatorSheet(point: RoutePoint): void {
    this.navigatorPoint.set(point);
    this.openSheet.set('navigator');
  }

  protected chooseNavigator(app: NavigatorApp): void {
    const point = this.navigatorPoint();
    this.closeSheet();
    if (point) {
      this.navigators.openRoute(app, point);
    } else {
      // Виклик із меню «Змінити навігатор» — просто запамʼятовуємо вибір.
      this.navigators.set(app);
    }
  }

  // --- Меню ------------------------------------------------------------------

  protected openMenu(): void {
    this.openSheet.set('menu');
  }

  protected closeSheet(): void {
    this.openSheet.set('none');
    this.navigatorPoint.set(null);
    this.delayPoint.set(null);
  }

  /** Друкована версія маршрутного листа у новій вкладці (DRV-40, PRN-01). */
  protected openPrint(): void {
    this.closeSheet();
    const date = this.store.selectedDate();
    if (typeof window !== 'undefined' && typeof window.open === 'function') {
      window.open(`/print/${date}`, '_blank', 'noopener');
    }
  }

  protected changeNavigator(): void {
    this.navigatorPoint.set(null);
    this.openSheet.set('navigator');
  }

  protected async logout(): Promise<void> {
    this.closeSheet();
    this.store.reset();
    await firstValueFrom(this.auth.logout());
    await this.router.navigate(['/login']);
  }

  protected async installApp(): Promise<void> {
    await this.install.promptInstall();
  }

  protected dismissInstall(): void {
    this.install.dismissForever();
  }

  private showToast(message: string): void {
    this.clearToastTimer();
    this.toast.set(message);
    if (typeof setTimeout === 'function') {
      this.toastTimer = setTimeout(() => this.toast.set(null), TOAST_MS);
    }
  }

  private clearToastTimer(): void {
    if (this.toastTimer !== null) {
      clearTimeout(this.toastTimer);
      this.toastTimer = null;
    }
  }
}
