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
import { ArrivalQueueService } from '../../core/offline/arrival-queue.service';
import { NavigatorPreferenceService } from '../../core/nav/navigator-preference.service';
import { InstallPromptService } from '../../core/pwa/install-prompt.service';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { PointCardComponent } from './point-card.component';
import { DelayFormComponent } from './delay-form.component';
import { BottomSheetComponent } from '../../shared/ui/bottom-sheet.component';
import { ConfirmDialogComponent } from '../../shared/ui/confirm-dialog.component';
import type { DelayPayload, RoutePoint } from '../../core/models/route-sheet.model';
import type { NavigatorApp } from '../../core/util/deep-links';
import {
  dateChipLabel,
  formatKyivTime,
  kyivDateKey,
} from '../../core/util/time.util';

type SheetKind = 'none' | 'menu' | 'navigator' | 'delay';

@Component({
  selector: 'app-route-sheet-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslatePipe,
    PointCardComponent,
    DelayFormComponent,
    BottomSheetComponent,
    ConfirmDialogComponent,
  ],
  templateUrl: './route-sheet.page.html',
  styleUrl: './route-sheet.page.scss',
})
export class RouteSheetPage implements OnInit {
  protected readonly store = inject(RouteSheetStore);
  protected readonly network = inject(NetworkService);
  protected readonly queue = inject(ArrivalQueueService);
  protected readonly install = inject(InstallPromptService);
  private readonly auth = inject(AuthService);
  private readonly navigators = inject(NavigatorPreferenceService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly injector = inject(Injector);
  private scrolledToActive = false;

  /** Перемальовує залежні від часу стани (вікно «На місці») раз на хвилину. */
  protected readonly nowTick = signal(Date.now());

  protected readonly openSheet = signal<SheetKind>('none');
  protected readonly confirmPoint = signal<RoutePoint | null>(null);
  protected readonly delayPoint = signal<RoutePoint | null>(null);
  protected readonly navigatorPoint = signal<RoutePoint | null>(null);
  protected readonly toast = signal<string | null>(null);
  protected readonly arrivingId = signal<string | null>(null);
  protected readonly delayError = signal<string | null>(null);
  protected readonly delayBusy = signal(false);

  protected readonly profile = this.auth.profile;

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

  protected readonly lastSyncLabel = computed(() => {
    const at = this.store.lastSyncAt();
    return at ? this.i18n.t('sheet.updatedAt', { time: formatKyivTime(at) }) : null;
  });

  protected readonly confirmHeading = computed(() => {
    const point = this.confirmPoint();
    return point
      ? this.i18n.t('arrive.confirmTitle', { store: point.store.name })
      : '';
  });

  ngOnInit(): void {
    this.install.init();
    void this.store.initialize();
    // Полінг статусів раз на 30 с (RT-04).
    this.store.startPolling();
    // Автоматична відправка черги офлайн-відміток при відновленні звʼязку (DRV-34).
    effect(
      () => {
        if (this.network.online() && this.queue.pendingCount() > 0) {
          void this.store.flushQueue();
        }
      },
      { injector: this.injector },
    );
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
    const tick = setInterval(() => this.nowTick.set(Date.now()), 60_000);
    this.destroyRef.onDestroy(() => {
      clearInterval(tick);
      this.store.stopPolling();
    });
  }

  protected windowStateFor(point: RoutePoint) {
    return this.store.windowState(point, this.nowTick());
  }

  protected isActive(index: number): boolean {
    return index === this.store.activeIndex();
  }

  protected async selectDate(date: string): Promise<void> {
    this.scrolledToActive = false;
    await this.store.selectDate(date);
  }

  protected async refresh(): Promise<void> {
    await this.store.refresh();
  }

  // --- Маршрут у навігаторі -------------------------------------------------

  protected onRouteRequested(point: RoutePoint): void {
    const preferred = this.navigators.preferred();
    if (preferred) {
      this.navigators.openRoute(preferred, point.store);
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
      this.navigators.openRoute(app, point.store);
    } else {
      // Виклик із меню «Змінити навігатор» — просто запамʼятовуємо вибір.
      this.navigators.set(app);
    }
  }

  // --- Відмітка «На місці» ---------------------------------------------------

  protected requestArrive(point: RoutePoint): void {
    this.confirmPoint.set(point);
  }

  protected cancelArrive(): void {
    this.confirmPoint.set(null);
  }

  protected async confirmArrive(): Promise<void> {
    const point = this.confirmPoint();
    this.confirmPoint.set(null);
    if (!point) {
      return;
    }
    // ФАКТИЧНИЙ час натискання фіксується тут і передається на сервер (DRV-34).
    const pressedAt = new Date().toISOString();
    this.arrivingId.set(point.bookingId);
    const result = await this.store.markArrived(point.bookingId, pressedAt);
    this.arrivingId.set(null);
    if (result.queued) {
      this.showToast(this.i18n.t('arrive.queuedOffline'));
    } else if (!result.ok && result.message) {
      this.showToast(result.message);
    }
  }

  // --- orderId ---------------------------------------------------------------

  protected async saveOrderId(event: {
    point: RoutePoint;
    orderId: string;
  }): Promise<void> {
    const result = await this.store.saveOrderId(event.point.bookingId, event.orderId);
    if (!result.ok && result.message) {
      this.showToast(result.message);
    }
  }

  // --- Затримка --------------------------------------------------------------

  protected openDelay(point: RoutePoint): void {
    this.delayPoint.set(point);
    this.delayError.set(null);
    this.openSheet.set('delay');
  }

  protected async submitDelay(payload: DelayPayload): Promise<void> {
    const point = this.delayPoint();
    if (!point) {
      return;
    }
    this.delayBusy.set(true);
    const result = await this.store.reportDelay(point.bookingId, payload);
    this.delayBusy.set(false);
    if (result.ok) {
      this.closeSheet();
    } else {
      this.delayError.set(result.message ?? this.i18n.t('delay.error.generic'));
    }
  }

  // --- Меню ------------------------------------------------------------------

  protected openMenu(): void {
    this.openSheet.set('menu');
  }

  protected closeSheet(): void {
    this.openSheet.set('none');
    this.delayPoint.set(null);
    this.navigatorPoint.set(null);
    this.delayError.set(null);
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
    this.toast.set(message);
    setTimeout(() => this.toast.set(null), 4000);
  }
}
