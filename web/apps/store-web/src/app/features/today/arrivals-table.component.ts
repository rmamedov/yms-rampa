import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { Booking, Ramp } from '../../core/models';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { StatusBadgeComponent } from '../../shared/status-badge.component';

/** Порядок сортування за часом слота. */
export type SlotSort = 'asc' | 'desc';

/**
 * Список прибуття таблицею.
 *
 * Колонки взяті з реальних полів бронювання. Двох колонок із макета тут немає
 * свідомо:
 *   «Пріоритет» — у домені такого поняття не існує, і малювати кольорову
 *     крапку, яка нічого не означає, гірше, ніж не малювати;
 *   «Слот» окремо від «Час слоту» — це те саме значення двічі.
 *
 * ETA/очікування рахується з фактичних позначок часу: прибув, у роботі з,
 * розвантажено о, а для затриманих — обіцяний час зі скарги водія.
 */
@Component({
  selector: 'app-arrivals-table',
  standalone: true,
  imports: [TranslatePipe, StatusBadgeComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './arrivals-table.component.html',
})
export class ArrivalsTableComponent {
  private readonly i18n = inject(I18nService);

  readonly bookings = input.required<readonly Booking[]>();
  readonly ramps = input.required<readonly Ramp[]>();
  readonly nowIso = input.required<string>();
  readonly readOnly = input(false);
  readonly openBooking = output<Booking>();

  protected readonly sort = signal<SlotSort>('asc');

  protected readonly rows = computed(() => {
    const direction = this.sort() === 'asc' ? 1 : -1;
    return [...this.bookings()].sort(
      (a, b) => direction * a.slotStart.localeCompare(b.slotStart),
    );
  });

  protected toggleSort(): void {
    this.sort.update((current) => (current === 'asc' ? 'desc' : 'asc'));
  }

  protected rampName(rampId: string): string {
    return this.ramps().find((r) => r.rampId === rampId)?.name ?? rampId;
  }

  /** «08:00 – 09:00» у часі магазину. */
  protected slotRange(booking: Booking): string {
    return `${booking.localTime} – ${this.localTime(booking.slotEnd)}`;
  }

  /**
   * Другий рядок під назвою постачальника.
   *
   * У макеті тут «Код: 100247», але коду постачальника в домені немає —
   * є номер замовлення, і для приймальника він корисніший: саме за ним він
   * звіряє машину з накладною.
   */
  protected supplierNote(booking: Booking): string {
    return booking.orderId
      ? this.i18n.translate('list.order', { order: booking.orderId })
      : '';
  }

  /**
   * Колонка «ETA / Очікування»: що з цією машиною відбувається просто зараз.
   * Порядок гілок — від найпізнішої події до найранішої.
   */
  protected etaLine(booking: Booking): { main: string; note: string; tone: string } {
    if (booking.completedAt) {
      return {
        main: '',
        note: this.i18n.translate('list.eta.completed', {
          time: this.localTime(booking.completedAt),
        }),
        tone: 'muted',
      };
    }
    if (booking.unloadingStartedAt) {
      return {
        main: '',
        note: this.i18n.translate('list.eta.unloading', {
          time: this.localTime(booking.unloadingStartedAt),
        }),
        tone: 'muted',
      };
    }
    if (booking.arrivedAt) {
      const waited = this.minutesBetween(booking.arrivedAt, this.nowIso());
      return {
        main: this.i18n.translate('list.eta.waiting', { minutes: waited }),
        note: this.i18n.translate('list.eta.arrived', {
          time: this.localTime(booking.arrivedAt),
        }),
        tone: 'wait',
      };
    }
    if (booking.delayed.flag) {
      const late = booking.delayed.eta
        ? this.minutesBetween(booking.slotStart, booking.delayed.eta)
        : null;
      return {
        main: late === null ? '' : this.i18n.translate('list.eta.late', { minutes: late }),
        note: booking.delayed.eta
          ? this.i18n.translate('list.eta.expected', {
              time: this.localTime(booking.delayed.eta),
            })
          : '',
        tone: 'late',
      };
    }
    return {
      main: '',
      note: this.i18n.translate('list.eta.expected', { time: booking.localTime }),
      tone: 'muted',
    };
  }

  private localTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('uk-UA', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: 'Europe/Kyiv',
    });
  }

  private minutesBetween(fromIso: string, toIso: string): number {
    return Math.max(
      0,
      Math.round((Date.parse(toIso) - Date.parse(fromIso)) / 60_000),
    );
  }
}
