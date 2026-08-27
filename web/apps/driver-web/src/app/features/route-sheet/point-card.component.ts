import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
  signal,
} from '@angular/core';
import { LowerCasePipe } from '@angular/common';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { StatusBadgeComponent } from '../../shared/ui/status-badge.component';
import type { RoutePoint } from '../../core/models/route-sheet.model';
import type { DelayState } from '../../core/models/booking-action.model';
import { isClosedPoint } from '../../core/state/route-sheet.store';
import { formatKyivTime } from '../../core/util/time.util';

/** Дії водія доступні рівно доти, доки бекенд їх приймає (Booking, 6.5). */
const OPEN_FOR_DRIVER: readonly RoutePoint['status'][] = ['booked', 'arrived'];

/**
 * Картка точки маршруту з діями контуру водія.
 *
 * Кнопки показуються рівно там, де бекенд їх прийме:
 *  - «На місці» — лише зі статусу `booked` (ST-01, booked → arrived);
 *  - затримка і orderId — у `booked` та `arrived`, бо після початку
 *    розвантаження обидві дії дають 422.
 */
@Component({
  selector: 'app-point-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, LowerCasePipe, StatusBadgeComponent],
  templateUrl: './point-card.component.html',
  styleUrl: './point-card.component.scss',
  host: {
    '[class.active]': 'active()',
    '[class.closed]': 'closed()',
  },
})
export class PointCardComponent {
  readonly point = input.required<RoutePoint>();
  readonly active = input(false);
  /** Дія над цією точкою вже виконується. */
  readonly pending = input(false);
  /** Відмітка «На місці» стоїть у черзі до появи звʼязку. */
  readonly queued = input(false);
  readonly delay = input<DelayState | null>(null);

  readonly routeRequested = output<RoutePoint>();
  readonly routeOptionsRequested = output<RoutePoint>();
  readonly arriveRequested = output<RoutePoint>();
  readonly delayRequested = output<RoutePoint>();
  readonly orderIdSubmitted = output<{ point: RoutePoint; orderId: string | null }>();

  protected readonly closed = computed(() => isClosedPoint(this.point()));
  /** Завершена точка згортається в компактний рядок (8.7). */
  protected readonly collapsed = computed(() => this.point().status === 'completed');

  protected readonly canArrive = computed(() => this.point().status === 'booked');
  protected readonly canEdit = computed(() =>
    OPEN_FOR_DRIVER.includes(this.point().status),
  );

  protected readonly delayEta = computed(() => {
    const eta = this.delay()?.eta;
    return eta ? formatKyivTime(eta) : null;
  });

  protected readonly editingOrder = signal(false);
  protected readonly orderDraft = signal('');

  protected onRouteClick(): void {
    this.routeRequested.emit(this.point());
  }

  /** Довге натискання відкриває вибір навігатора повторно (DRV-22). */
  protected onRouteContextMenu(event: Event): void {
    event.preventDefault();
    this.routeOptionsRequested.emit(this.point());
  }

  protected onArrive(): void {
    this.arriveRequested.emit(this.point());
  }

  protected onDelay(): void {
    this.delayRequested.emit(this.point());
  }

  protected startOrderEdit(): void {
    this.orderDraft.set(this.point().orderId ?? '');
    this.editingOrder.set(true);
  }

  protected cancelOrderEdit(): void {
    this.editingOrder.set(false);
  }

  protected onOrderInput(event: Event): void {
    this.orderDraft.set((event.target as HTMLInputElement).value);
  }

  /** Порожній рядок — це очищення номера: бекенд приймає `orderId: null`. */
  protected submitOrder(): void {
    const value = this.orderDraft().trim();
    this.editingOrder.set(false);
    this.orderIdSubmitted.emit({
      point: this.point(),
      orderId: value === '' ? null : value,
    });
  }
}
