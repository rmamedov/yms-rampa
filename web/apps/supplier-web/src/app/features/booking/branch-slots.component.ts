import {
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { BookingApi, CatalogApi } from '../../core/api/contracts';
import { toProblem } from '../../core/api/problem';
import type {
  BranchDetail,
  HoldSession,
  SlotGrid,
} from '../../core/models/models';
import { buildDateStrip, clampOffset } from '../../core/util/date-strip';
import {
  addDays,
  kyivDateIso,
  kyivDayLabel,
  kyivTimeHm,
  kyivWeekdayLabel,
} from '../../core/util/kyiv-time';
import {
  buildSlotRows,
  isSelectableState,
  type SlotCell,
} from '../../core/util/slot-matrix';
import { ToastService } from '../../shared/ui/toast.service';
import { TransferService } from '../../core/services/transfer.service';
import { BookingPanelComponent } from './booking-panel.component';

const POLL_MS = 60_000;
const VISIBLE_DAYS = 7;

@Component({
  selector: 'app-branch-slots',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, BookingPanelComponent],
  templateUrl: './branch-slots.component.html',
  styleUrl: './branch-slots.component.scss',
})
export class BranchSlotsComponent implements OnInit, OnDestroy {
  private readonly catalog = inject(CatalogApi);
  private readonly bookingApi = inject(BookingApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly router = inject(Router);
  private readonly transfer = inject(TransferService);

  readonly storeId = input.required<string>();

  protected readonly branch = signal<BranchDetail | null>(null);
  protected readonly grid = signal<SlotGrid | null>(null);
  protected readonly loading = signal(true);
  protected readonly offset = signal(0);
  protected readonly selectedDate = signal(kyivDateIso(new Date()));
  protected readonly hold = signal<HoldSession | null>(null);
  protected readonly today = signal(kyivDateIso(new Date()));
  protected readonly transferSource = this.transfer.source;

  private poll: ReturnType<typeof setInterval> | null = null;
  private readonly onFocus = () => this.refresh();

  protected readonly horizonDays = computed(
    () => this.branch()?.bookingHorizonDays ?? VISIBLE_DAYS,
  );

  /** Бекенд віддає плоский список слотів — матрицю «час × рампа» будуємо тут. */
  protected readonly rows = computed(() =>
    buildSlotRows(this.grid()?.slots ?? [], this.branch()?.ramps ?? []),
  );

  /** Підпис банера перенесення (SUP-RS-03). */
  protected readonly transferLabel = computed(() => {
    const source = this.transfer.source();
    if (!source) {
      return null;
    }
    const start = new Date(source.slotStart);
    return this.i18n.t('slots.transferBanner', {
      date: kyivDayLabel(kyivDateIso(start)),
      time: kyivTimeHm(start),
    });
  });

  protected readonly strip = computed(() =>
    buildDateStrip(
      this.today(),
      this.offset(),
      VISIBLE_DAYS,
      this.horizonDays(),
    ),
  );

  ngOnInit(): void {
    this.catalog.branch(this.storeId()).subscribe({
      next: (branch) => {
        this.branch.set(branch);
        this.loadGrid();
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.toasts.problem(error);
      },
    });
    this.poll = setInterval(() => this.refresh(), POLL_MS);
    window.addEventListener('focus', this.onFocus);
  }

  ngOnDestroy(): void {
    if (this.poll) {
      clearInterval(this.poll);
    }
    window.removeEventListener('focus', this.onFocus);
  }

  protected dayLabel(dateIso: string): string {
    return kyivDayLabel(dateIso);
  }

  protected weekday(dateIso: string): string {
    return kyivWeekdayLabel(dateIso);
  }

  protected selectDate(dateIso: string): void {
    this.selectedDate.set(dateIso);
    this.loadGrid();
  }

  protected shift(delta: number): void {
    const next = clampOffset(
      this.offset() + delta * VISIBLE_DAYS,
      VISIBLE_DAYS,
      this.horizonDays(),
    );
    this.offset.set(next);
    const dates = this.strip().dates;
    if (dates.length && !dates.includes(this.selectedDate())) {
      this.selectDate(dates[0]);
    }
  }

  protected loadGrid(): void {
    this.loading.set(true);
    this.catalog.slots(this.storeId(), this.selectedDate()).subscribe({
      next: (grid) => {
        this.grid.set(grid);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        const problem = toProblem(error);
        this.toasts.error(this.toasts.messageFor(problem));
      },
    });
  }

  protected refresh(): void {
    if (this.hold()) {
      return;
    }
    this.loadGrid();
  }

  protected rampName(rampId: string): string {
    return (
      this.branch()?.ramps.find((ramp) => ramp.rampId === rampId)?.name ?? ''
    );
  }

  protected stateLabel(cell: SlotCell): string {
    // GRID-04: власний резерв — єдина мітка «моє», яку віддає сітка;
    // чиє саме бронювання зайняло слот, бекенд не розкриває.
    if (cell.state === 'available' && cell.mine) {
      return this.i18n.t('slots.state.availableMine');
    }
    return this.i18n.t(`slots.state.${cell.state}`);
  }

  protected cellAria(cell: SlotCell, time: string): string {
    return this.i18n.t('slots.cellAria', {
      ramp: this.rampName(cell.rampId),
      time,
      state: this.stateLabel(cell),
    });
  }

  protected selectable(cell: SlotCell): boolean {
    return isSelectableState(cell.state);
  }

  /** Клік по вільному слоту створює hold і відкриває панель бронювання. */
  protected pick(cell: SlotCell): void {
    if (!this.selectable(cell) || this.hold()) {
      return;
    }
    this.bookingApi
      .hold({
        storeId: this.storeId(),
        rampId: cell.rampId,
        slotStart: cell.slotStart,
      })
      .subscribe({
        next: (session) => this.hold.set(session),
        error: (error: unknown) => {
          this.toasts.problem(error);
          this.loadGrid();
        },
      });
  }

  protected closePanel(): void {
    this.hold.set(null);
    this.loadGrid();
  }

  protected onConflict(): void {
    this.hold.set(null);
    this.loadGrid();
  }

  protected onBooked(): void {
    this.hold.set(null);
    const date = this.selectedDate();
    this.loadGrid();
    void this.router.navigate(['/route-sheets', date]);
  }

  protected cancelTransfer(): void {
    this.transfer.clear();
  }

  protected nextDayAvailable(): boolean {
    return this.strip().canNext;
  }

  protected addDaysLabel(dateIso: string, days: number): string {
    return kyivDayLabel(addDays(dateIso, days));
  }
}
