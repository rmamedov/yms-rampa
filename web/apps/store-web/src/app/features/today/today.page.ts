import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnDestroy,
  OnInit,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { BoardStore, BoardViewMode } from '../../core/data/board.store';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import {
  Booking,
  CompleteUnloadingPayload,
  DelayPayload,
  ReassignPayload,
  RejectPayload,
} from '../../core/models/booking.model';
import { BookingActionId } from '../../core/util/booking-rules.util';
import {
  formatDate,
  formatTime,
  toKyivDateKey,
} from '../../core/util/date.util';
import { placeOnTimeline, timelineTicks, toHhMmLabel } from './timeline.util';
import { BookingCardComponent } from '../../shared/booking-card.component';
import { FiltersBarComponent } from '../../shared/filters-bar.component';
import { StatsBarComponent } from '../../shared/stats-bar.component';
import { CompleteDialogComponent } from './dialogs/complete-dialog.component';
import { ConfirmDialogComponent } from './dialogs/confirm-dialog.component';
import { DelayDialogComponent } from './dialogs/delay-dialog.component';
import { RejectDialogComponent } from './dialogs/reject-dialog.component';
import { ReassignDialogComponent } from './dialogs/reassign-dialog.component';
import { AuditDialogComponent } from './dialogs/audit-dialog.component';
import { WalkInDialogComponent } from '../walk-in/walk-in-dialog.component';

type DialogKind =
  | 'complete'
  | 'noShow'
  | 'reject'
  | 'delay'
  | 'reassign'
  | 'log'
  | 'walkIn'
  | null;

/** Головний екран «Сьогодні» (розділи 9.2, 9.3, 9.6). */
@Component({
  selector: 'app-today-page',
  standalone: true,
  imports: [
    FormsModule,
    TranslatePipe,
    BookingCardComponent,
    FiltersBarComponent,
    StatsBarComponent,
    CompleteDialogComponent,
    ConfirmDialogComponent,
    DelayDialogComponent,
    RejectDialogComponent,
    ReassignDialogComponent,
    AuditDialogComponent,
    WalkInDialogComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './today.page.html',
})
export class TodayPage implements OnInit, OnDestroy {
  readonly store = inject(BoardStore);
  private readonly i18n = inject(I18nService);

  readonly dialog = signal<DialogKind>(null);
  readonly selected = signal<Booking | null>(null);

  readonly dateLabel = computed(() => formatDate(this.store.viewDateKey()));

  readonly lastSyncLabel = computed(() => {
    const last = this.store.lastSyncAt();
    return last === null ? '' : formatTime(new Date(last).toISOString());
  });

  readonly ticks = computed(() =>
    timelineTicks(this.store.timelineBounds()).map((minutes) => ({
      minutes,
      label: toHhMmLabel(minutes),
      leftPercent: this.tickLeft(minutes),
    })),
  );

  readonly noShowParams = computed<Record<string, string | number>>(() => {
    const booking = this.selected();
    if (!booking) return {} as Record<string, string | number>;
    return {
      supplier: booking.supplierName,
      plate: booking.vehicle.plateNumber,
      slot: `${formatTime(booking.slotStart)}–${formatTime(booking.slotEnd)}`,
    };
  });

  ngOnInit(): void {
    this.store.load();
    this.store.startPolling();
  }

  ngOnDestroy(): void {
    this.store.stopPolling();
  }

  setMode(mode: BoardViewMode): void {
    this.store.setViewMode(mode);
  }

  onDateInput(value: string): void {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      this.store.setDate(value);
    }
  }

  isAtRisk(booking: Booking): boolean {
    return this.store.risk().atRiskBookingIds.includes(booking.id);
  }

  isOverrun(booking: Booking): boolean {
    return this.store.risk().overrunBookingIds.includes(booking.id);
  }

  /**
   * Підказка чипа таймлайну.
   *
   * Підпис чипа — назва постачальника, і на реальній дошці вона в усіх чипів
   * та сама (одна філія — один-два великі постачальники). Тому підказка має
   * називати саме це бронювання: час слоту, постачальника і номер авто —
   * інакше з таймлайну неможливо зрозуміти, про яку машину йдеться.
   */
  timelineTitle(booking: Booking): string {
    return `${formatTime(booking.slotStart)}–${formatTime(booking.slotEnd)} · ${
      booking.supplierName
    } · ${booking.vehicle.plateNumber}`;
  }

  placement(booking: Booking): { left: string; width: string } {
    const placed = placeOnTimeline(booking, this.store.timelineBounds());
    return {
      left: `${placed.leftPercent}%`,
      width: `${placed.widthPercent}%`,
    };
  }

  private tickLeft(minutes: number): number {
    const bounds = this.store.timelineBounds();
    const span = Math.max(1, bounds.endMinutes - bounds.startMinutes);
    return ((minutes - bounds.startMinutes) / span) * 100;
  }

  // --- Дії --------------------------------------------------------------

  onAction(booking: Booking, action: BookingActionId): void {
    this.selected.set(booking);
    switch (action) {
      case 'arrived':
        this.store.markArrived(booking);
        this.selected.set(null);
        break;
      case 'startUnloading':
        this.store.startUnloading(booking);
        this.selected.set(null);
        break;
      case 'complete':
        this.dialog.set('complete');
        break;
      case 'noShow':
        this.dialog.set('noShow');
        break;
      case 'reject':
        this.dialog.set('reject');
        break;
      case 'delay':
        this.dialog.set('delay');
        break;
      case 'reassign':
        this.dialog.set('reassign');
        break;
      case 'log':
        this.dialog.set('log');
        break;
    }
  }

  closeDialog(): void {
    this.dialog.set(null);
    this.selected.set(null);
  }

  openWalkIn(): void {
    this.dialog.set('walkIn');
  }

  confirmComplete(payload: CompleteUnloadingPayload): void {
    const booking = this.selected();
    if (booking) this.store.complete(booking, payload);
    this.closeDialog();
  }

  confirmNoShow(): void {
    const booking = this.selected();
    if (booking) this.store.markNoShow(booking);
    this.closeDialog();
  }

  confirmReject(payload: RejectPayload): void {
    const booking = this.selected();
    if (booking) this.store.reject(booking, payload);
    this.closeDialog();
  }

  confirmDelay(payload: DelayPayload): void {
    const booking = this.selected();
    if (booking) this.store.setDelay(booking, payload);
    this.closeDialog();
  }

  confirmReassign(payload: ReassignPayload): void {
    const booking = this.selected();
    if (booking) this.store.reassign(booking, payload);
    this.closeDialog();
  }

  goToday(): void {
    this.store.setDate(toKyivDateKey(new Date()));
  }
}
