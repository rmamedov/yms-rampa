import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { LowerCasePipe } from '@angular/common';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { StatusBadgeComponent } from '../../shared/ui/status-badge.component';
import type { RoutePoint } from '../../core/models/route-sheet.model';
import { formatKyivTime, formatSlotRange } from '../../core/util/time.util';
import { hasCoordinates } from '../../core/util/deep-links';
import { canEditOrderId, isClosedPoint } from '../../core/state/route-sheet.store';
import type { ArriveWindowState } from '../../core/util/time.util';

const ORDER_ID_MAX = 64;

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
  private readonly i18n = inject(I18nService);

  readonly point = input.required<RoutePoint>();
  readonly active = input(false);
  readonly windowState = input.required<ArriveWindowState>();
  /** Відмітка лежить у локальній черзі і чекає мережі (DRV-34). */
  readonly queued = input(false);
  /** Запит відмітки вже виконується — захист від повторного натискання. */
  readonly submitting = input(false);
  readonly online = input(true);

  readonly routeRequested = output<RoutePoint>();
  readonly routeOptionsRequested = output<RoutePoint>();
  readonly arriveRequested = output<RoutePoint>();
  readonly delayRequested = output<RoutePoint>();
  readonly orderIdSubmitted = output<{ point: RoutePoint; orderId: string }>();

  protected readonly editing = signal(false);
  protected readonly draftOrderId = signal('');
  protected readonly orderError = signal<string | null>(null);

  protected readonly slotRange = computed(() =>
    formatSlotRange(this.point().slotStart, this.point().slotEnd),
  );
  protected readonly closed = computed(() => isClosedPoint(this.point()));
  /** Завершена точка згортається в компактний рядок (8.7). */
  protected readonly collapsed = computed(() => this.point().status === 'completed');
  protected readonly canEditOrder = computed(
    () => canEditOrderId(this.point()) && this.online(),
  );
  protected readonly hasCoords = computed(() => hasCoordinates(this.point().store));
  protected readonly canArrive = computed(
    () =>
      this.point().status === 'booked' &&
      !this.queued() &&
      !this.submitting() &&
      this.windowState() !== 'too_early',
  );
  protected readonly showArrive = computed(
    () => this.point().status === 'booked' && !this.queued(),
  );
  protected readonly arrivedAtLabel = computed(() => {
    const at = this.point().arrivedAt;
    return at ? this.i18n.t('point.arrivedAt', { time: formatKyivTime(at) }) : null;
  });
  protected readonly delayLabel = computed(() => {
    const delayed = this.point().delayed;
    if (!delayed) {
      return null;
    }
    return delayed.eta
      ? this.i18n.t('status.delayedEta', { time: formatKyivTime(delayed.eta) })
      : this.i18n.t('status.delayed');
  });
  protected readonly arriveHint = computed(() => {
    if (this.queued()) {
      return this.i18n.t('point.arriveQueued');
    }
    if (this.windowState() === 'too_early') {
      return this.i18n.t('point.arriveTooEarly');
    }
    if (this.windowState() === 'late') {
      return this.i18n.t('point.arriveLate');
    }
    return null;
  });

  protected startEdit(): void {
    this.draftOrderId.set(this.point().orderId ?? '');
    this.orderError.set(null);
    this.editing.set(true);
  }

  protected cancelEdit(): void {
    this.editing.set(false);
    this.orderError.set(null);
  }

  protected onOrderInput(event: Event): void {
    this.draftOrderId.set((event.target as HTMLInputElement).value);
    this.orderError.set(null);
  }

  protected submitOrder(): void {
    const value = this.draftOrderId().trim();
    if (value.length === 0) {
      this.orderError.set(this.i18n.t('point.orderIdRequired'));
      return;
    }
    if (value.length > ORDER_ID_MAX) {
      this.orderError.set(this.i18n.t('point.orderIdTooLong'));
      return;
    }
    this.editing.set(false);
    this.orderIdSubmitted.emit({ point: this.point(), orderId: value });
  }

  protected onRouteClick(): void {
    this.routeRequested.emit(this.point());
  }

  /** Довге натискання відкриває вибір навігатора повторно (DRV-22). */
  protected onRouteContextMenu(event: Event): void {
    event.preventDefault();
    this.routeOptionsRequested.emit(this.point());
  }
}
