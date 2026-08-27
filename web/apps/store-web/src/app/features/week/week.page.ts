import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { BoardStore } from '../../core/data/board.store';
import { StoreGateway, WeekDaySlots } from '../../core/data/gateways';
import { I18nService } from '../../core/i18n/i18n.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { Slot } from '../../core/models/store.model';
import {
  addDaysToDateKey,
  formatDate,
  formatWeekday,
  startOfKyivWeek,
  toKyivDateKey,
} from '../../core/util/date.util';

interface DayCell {
  readonly dateKey: string;
  readonly weekday: string;
  readonly date: string;
  readonly slots: readonly Slot[];
  readonly densityPercent: number;
}

/** Розклад тижня — тільки читання (STW-25). */
@Component({
  selector: 'app-week-page',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './week.page.html',
})
export class WeekPage implements OnInit {
  private readonly gateway = inject(StoreGateway);
  private readonly auth = inject(AuthService);
  private readonly board = inject(BoardStore);
  private readonly i18n = inject(I18nService);
  private readonly router = inject(Router);

  readonly monday = signal(startOfKyivWeek(toKyivDateKey(new Date())));
  readonly week = signal<readonly WeekDaySlots[]>([]);
  readonly loading = signal(true);

  readonly ramps = this.board.ramps;

  readonly days = computed<DayCell[]>(() =>
    this.week().map((day) => {
      const total = day.slots.length;
      const busy = day.slots.filter(
        (s) => s.state === 'booked' || s.state === 'blocked' || s.state === 'reserved',
      ).length;
      return {
        dateKey: day.dateKey,
        weekday: formatWeekday(`${day.dateKey}T12:00:00Z`),
        date: formatDate(`${day.dateKey}T12:00:00Z`),
        slots: day.slots,
        densityPercent: total === 0 ? 0 : Math.round((busy / total) * 100),
      };
    }),
  );

  readonly rangeLabel = computed(
    () =>
      `${formatDate(`${this.monday()}T12:00:00Z`)} — ${formatDate(
        `${addDaysToDateKey(this.monday(), 6)}T12:00:00Z`,
      )}`,
  );

  ngOnInit(): void {
    if (!this.board.config()) {
      this.board.load();
    }
    // Без переліку філій магазин не обрано (мережева роль) — тягнути тиждень
    // не було б звідки.
    this.loading.set(true);
    this.auth.ensureStores().subscribe({
      next: () => this.fetch(),
      error: () => this.loading.set(false),
    });
  }

  shiftWeek(weeks: number): void {
    this.monday.set(addDaysToDateKey(this.monday(), weeks * 7));
    this.fetch();
  }

  openDay(dateKey: string): void {
    this.board.setDate(dateKey);
    void this.router.navigateByUrl('/today');
  }

  slotsForRamp(day: DayCell, rampId: string): readonly Slot[] {
    return day.slots
      .filter((s) => s.rampId === rampId)
      .sort((a, b) => a.slotStart.localeCompare(b.slotStart));
  }

  /** Підказка клітинки: локальний час слота + його стан. */
  slotTitle(slot: Slot): string {
    return `${slot.localStart} · ${this.i18n.translate(`slotState.${slot.state}`)}`;
  }

  private fetch(): void {
    const store = this.auth.selectedStore();
    if (!store) return;
    this.loading.set(true);
    this.gateway.getWeek(store.storeId, this.monday()).subscribe({
      next: (week) => {
        this.week.set(week);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
