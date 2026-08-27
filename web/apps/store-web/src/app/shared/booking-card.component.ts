import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { Booking } from '../core/models/booking.model';
import { I18nService } from '../core/i18n/i18n.service';
import { TranslatePipe } from '../core/i18n/translate.pipe';
import { BoardStore } from '../core/data/board.store';
import { formatTime } from '../core/util/date.util';
import {
  BookingActionId,
  nextSwipeAction,
} from '../core/util/booking-rules.util';
import {
  statusTone,
  unloadingDurationMinutes,
  waitingMinutes,
} from '../core/util/status.util';
import { StatusBadgeComponent } from './status-badge.component';

const SWIPE_THRESHOLD = 64;

/** Картка бронювання (STW-07) зі свайп-діями (STW-31). */
@Component({
  selector: 'app-booking-card',
  standalone: true,
  imports: [TranslatePipe, StatusBadgeComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './booking-card.component.html',
})
export class BookingCardComponent {
  private readonly store = inject(BoardStore);
  private readonly i18n = inject(I18nService);

  readonly booking = input.required<Booking>();
  readonly atRisk = input(false);
  readonly overrun = input(false);
  readonly compact = input(false);

  readonly action = output<BookingActionId>();

  readonly offset = signal(0);
  private startX = 0;
  private tracking = false;

  readonly tone = computed(() => statusTone(this.booking().status));

  readonly cardClasses = computed(() => {
    const classes = ['bcard', `bcard--${this.tone()}`];
    if (this.atRisk()) classes.push('bcard--risk');
    if (this.overrun()) classes.push('bcard--overrun');
    if (this.booking().delayed.flag) classes.push('bcard--delayed');
    if (this.store.busyBookingId() === this.booking().id) {
      classes.push('bcard--busy');
    }
    return classes.join(' ');
  });

  readonly slotLabel = computed(
    () =>
      `${formatTime(this.booking().slotStart)}–${formatTime(this.booking().slotEnd)}`,
  );

  readonly delayedLabel = computed(() =>
    this.i18n.translate('card.delayedEta', {
      time: formatTime(this.booking().delayed.eta),
    }),
  );

  readonly overrunLabel = computed(() => {
    const minutes = this.store.risk().overrunMinutes[this.booking().id];
    return minutes === undefined
      ? this.i18n.translate('board.overrun')
      : this.i18n.translate('board.overrunBy', { minutes });
  });

  readonly palletsLabel = computed(() => {
    const b = this.booking();
    return b.unloadedPalletsCount === null
      ? String(b.palletsCount)
      : this.i18n.translate('card.unloadedPallets', {
          done: b.unloadedPalletsCount,
          planned: b.palletsCount,
        });
  });

  /** Фактичні часові позначки картки одним списком. */
  readonly facts = computed<string[]>(() => {
    const b = this.booking();
    const out: string[] = [];
    if (b.arrivedAt) {
      out.push(
        this.i18n.translate('card.arrivedAt', { time: formatTime(b.arrivedAt) }),
      );
    }
    if (b.unloadingStartedAt) {
      out.push(
        this.i18n.translate('card.unloadingSince', {
          time: formatTime(b.unloadingStartedAt),
        }),
      );
    }
    const wait = waitingMinutes(b);
    if (wait !== null) {
      out.push(this.i18n.translate('card.waitMinutes', { minutes: wait }));
    }
    const duration = unloadingDurationMinutes(b);
    if (duration !== null) {
      out.push(this.i18n.translate('card.unloadDuration', { minutes: duration }));
    }
    // Причини приходять із бекенду вже україномовними рядками довідника.
    if (b.partialUnload?.flag) {
      out.push(
        `${this.i18n.translate('card.partial')} — ${b.partialUnload.reason}`,
      );
    }
    if (b.rejectedAt) {
      out.push(
        this.i18n.translate('card.rejected', { reason: b.rejectedAt.reason }),
      );
    }
    return out;
  });

  can(action: BookingActionId): boolean {
    return this.store.can(this.booking(), action);
  }

  tooltip(action: BookingActionId): string {
    const availability = this.store.reasonKey(this.booking(), action);
    if (!availability) return '';
    const params =
      availability === 'action.disabled.wrongStatus'
        ? { status: this.i18n.translate('status.arrived') }
        : undefined;
    return this.i18n.translate(availability, params);
  }

  emit(action: BookingActionId): void {
    if (action !== 'log' && !this.can(action)) return;
    this.action.emit(action);
  }

  // --- Свайпи (STW-31) --------------------------------------------------

  onTouchStart(event: TouchEvent): void {
    this.startX = event.changedTouches[0].clientX;
    this.tracking = true;
  }

  onTouchMove(event: TouchEvent): void {
    if (!this.tracking) return;
    const dx = event.changedTouches[0].clientX - this.startX;
    this.offset.set(Math.max(-120, Math.min(120, dx)));
  }

  onTouchEnd(): void {
    if (!this.tracking) return;
    this.tracking = false;
    const dx = this.offset();
    this.offset.set(0);

    if (dx >= SWIPE_THRESHOLD) {
      const next = nextSwipeAction(
        this.booking(),
        this.store.actionContext(this.booking()),
      );
      if (next) this.action.emit(next);
    } else if (dx <= -SWIPE_THRESHOLD) {
      this.action.emit('log');
    }
  }
}
