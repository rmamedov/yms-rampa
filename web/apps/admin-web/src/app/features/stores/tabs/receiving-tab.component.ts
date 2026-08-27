import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core';
import {
  CalendarException,
  DAYS_OF_WEEK,
  DayOfWeek,
  ExceptionType,
  ReceivingWindow,
  SlotSizeMinutes,
  TimeInterval,
} from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  IntervalError,
  normalizeReceivingWindows,
  validateException,
  validateReceivingWindows,
} from '../../../core/utils/store-config.util';
import { addDays, formatDate, kyivDate } from '../../../core/utils/time.util';

export interface ReceivingChange {
  readonly receivingWindows: readonly ReceivingWindow[];
  readonly exceptions: readonly CalendarException[];
}

/** Вкладка «Прийом поставок»: вікна по днях тижня + календар винятків (STC-10…STC-13). */
@Component({
  selector: 'app-store-receiving-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './receiving-tab.component.html',
})
export class StoreReceivingTabComponent {
  readonly windows = input.required<readonly ReceivingWindow[]>();
  readonly exceptions = input.required<readonly CalendarException[]>();
  readonly slotSizeMinutes = input.required<SlotSizeMinutes | null>();
  readonly canEdit = input(false);
  readonly changed = output<ReceivingChange>();

  protected readonly days = DAYS_OF_WEEK;
  protected readonly draftWindows = signal<ReceivingWindow[]>([]);
  protected readonly draftExceptions = signal<CalendarException[]>([]);

  protected readonly newExceptionDate = signal(addDays(kyivDate(), 7));
  protected readonly newExceptionType = signal<ExceptionType>('closed');
  protected readonly newExceptionReason = signal('');
  protected readonly newExceptionFrom = signal('09:00');
  protected readonly newExceptionTo = signal('13:00');
  protected readonly exceptionErrors = signal<readonly string[]>([]);

  protected readonly errors = computed<readonly IntervalError[]>(() =>
    validateReceivingWindows(this.draftWindows(), this.slotSizeMinutes()),
  );

  protected readonly formatDate = formatDate;

  constructor() {
    effect(() => {
      // Чернетка завжди містить усі сім днів: інакше редагування дня,
      // якого немає в конфігурації, не мало б на що подіяти.
      this.draftWindows.set(normalizeReceivingWindows(this.windows()));
      this.draftExceptions.set(this.exceptions().map((e) => ({ ...e })));
    });
  }

  protected intervalsFor(day: DayOfWeek): readonly TimeInterval[] {
    return this.draftWindows().find((w) => w.dayOfWeek === day)?.intervals ?? [];
  }

  protected errorFor(day: DayOfWeek, index: number): IntervalError | null {
    return (
      this.errors().find((e) => e.dayOfWeek === day && e.index === index) ?? null
    );
  }

  protected addInterval(day: DayOfWeek): void {
    this.updateDay(day, (intervals) => [...intervals, { from: '08:00', to: '18:00' }]);
  }

  protected removeInterval(day: DayOfWeek, index: number): void {
    this.updateDay(day, (intervals) => intervals.filter((_, i) => i !== index));
  }

  protected setIntervalTime(
    day: DayOfWeek,
    index: number,
    field: 'from' | 'to',
    value: string,
  ): void {
    this.updateDay(day, (intervals) =>
      intervals.map((interval, i) =>
        i === index ? { ...interval, [field]: value } : interval,
      ),
    );
  }

  /** Копіює графік дня на всі робочі дні Пн–Пт. */
  protected copyToWeekdays(day: DayOfWeek): void {
    const source = this.intervalsFor(day).map((i) => ({ ...i }));
    this.draftWindows.update((windows) =>
      windows.map((w) =>
        w.dayOfWeek <= 5
          ? { dayOfWeek: w.dayOfWeek, intervals: source.map((i) => ({ ...i })) }
          : w,
      ),
    );
    this.emit();
  }

  private updateDay(
    day: DayOfWeek,
    updater: (intervals: readonly TimeInterval[]) => TimeInterval[],
  ): void {
    this.draftWindows.update((windows) =>
      windows.map((w) =>
        w.dayOfWeek === day
          ? { dayOfWeek: day, intervals: updater(w.intervals) }
          : w,
      ),
    );
    this.emit();
  }

  protected addException(): void {
    const exception: CalendarException = {
      // Ключ винятку в бекенді — дата, тож id формується з неї.
      id: `exc-${this.newExceptionDate()}`,
      date: this.newExceptionDate(),
      type: this.newExceptionType(),
      intervals:
        this.newExceptionType() === 'custom'
          ? [{ from: this.newExceptionFrom(), to: this.newExceptionTo() }]
          : [],
      reason: this.newExceptionReason().trim(),
    };
    const errors = validateException(
      exception,
      this.draftExceptions(),
      this.slotSizeMinutes(),
    );
    this.exceptionErrors.set(errors);
    if (errors.length > 0) {
      return;
    }
    this.draftExceptions.update((list) =>
      [...list, exception].sort((a, b) => a.date.localeCompare(b.date)),
    );
    this.newExceptionReason.set('');
    this.emit();
  }

  protected removeException(id: string): void {
    this.draftExceptions.update((list) => list.filter((e) => e.id !== id));
    this.emit();
  }

  protected setExceptionType(event: Event): void {
    this.newExceptionType.set(
      (event.target as HTMLSelectElement).value as ExceptionType,
    );
  }

  protected intervalsLabel(exception: CalendarException): string {
    return exception.intervals.map((i) => `${i.from}–${i.to}`).join(', ');
  }

  private emit(): void {
    this.changed.emit({
      receivingWindows: this.draftWindows(),
      exceptions: this.draftExceptions(),
    });
  }
}
