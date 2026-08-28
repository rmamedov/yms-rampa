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
import {
  arrivalAvailable,
  rampLabel,
  type RoutePoint,
} from '../../core/models/route-sheet.model';
import { isClosedPoint } from '../../core/state/route-sheet.store';
import { formatKyivDayMonth, formatKyivTime } from '../../core/util/time.util';

/** Дії водія доступні рівно доти, доки бекенд їх приймає (Booking, 6.5). */
const OPEN_FOR_DRIVER: readonly RoutePoint['status'][] = ['booked', 'arrived'];

/**
 * Картка точки маршруту з діями контуру водія.
 *
 * Кнопки показуються рівно там, де бекенд їх прийме:
 *  - «На місці» — зі статусу `booked` І не раніше доби візиту (ArrivalWindow,
 *    розділ 8): на завтрашній точці замість дії стоїть неактивна кнопка
 *    з датою, коли відмітка відкриється;
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
  /**
   * Поточний момент, epoch ms. Приходить ззовні, а не читається з Date.now()
   * усередині: тоді доступність кнопки перераховується разом із тиком
   * сторінки (і на переході через північ), а тест може задати будь-який час.
   */
  readonly now = input(Date.now());

  readonly routeRequested = output<RoutePoint>();
  readonly routeOptionsRequested = output<RoutePoint>();
  readonly arriveRequested = output<RoutePoint>();
  readonly delayRequested = output<RoutePoint>();
  readonly orderIdSubmitted = output<{ point: RoutePoint; orderId: string | null }>();

  protected readonly closed = computed(() => isClosedPoint(this.point()));
  /** Завершена точка згортається в компактний рядок (8.7). */
  protected readonly collapsed = computed(() => this.point().status === 'completed');

  /** Вікно відмітки вже відкрите — доба візиту настала (ArrivalWindow). */
  protected readonly arrivalOpen = computed(() =>
    arrivalAvailable(this.point(), this.now()),
  );

  protected readonly canArrive = computed(
    () => this.point().status === 'booked' && this.arrivalOpen(),
  );

  /**
   * Дата, з якої відмітка стане доступною, або null — коли пояснювати нічого
   * (вікно вже відкрите або точка вже не в статусі «Очікує виїзду»).
   */
  protected readonly arrivalOpensOn = computed(() =>
    this.point().status === 'booked' && !this.arrivalOpen()
      ? formatKyivDayMonth(this.point().slotStart)
      : null,
  );

  protected readonly canEdit = computed(() =>
    OPEN_FOR_DRIVER.includes(this.point().status),
  );

  /** Що написано на воротах: номер, інакше назва рампи (DRV-21). */
  protected readonly ramp = computed(() => rampLabel(this.point()));

  /**
   * Затримка береться з САМОЇ точки листа: після перезавантаження сторінки
   * і після кожного полінгу банер підтверджується сервером (DLY-01).
   */
  protected readonly delayed = computed(() => {
    const delayed = this.point().delayed;
    return delayed?.flag ? delayed : null;
  });

  protected readonly delayEta = computed(() => {
    const eta = this.delayed()?.eta;
    return eta ? formatKyivTime(eta) : null;
  });

  /** Час фактичного прибуття у київському поданні, або null. */
  protected readonly arrivedAt = computed(() => {
    const arrivedAt = this.point().arrivedAt;
    return arrivedAt ? formatKyivTime(arrivedAt) : null;
  });

  /** Позначку запізнення ставить домен — тут вона лише показується. */
  protected readonly arrivedLate = computed(() => this.point().arrivedLate === true);

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
