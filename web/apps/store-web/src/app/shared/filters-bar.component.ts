import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { BoardStore } from '../core/data/board.store';
import { TranslatePipe } from '../core/i18n/translate.pipe';
import { BookingStatus } from '../core/models/booking.model';
import { ALL_STATUSES } from '../core/util/status.util';

/** Фільтри дошки (STW-23): рампа, постачальник, статус, delayed, walk-in. */
@Component({
  selector: 'app-filters-bar',
  standalone: true,
  imports: [FormsModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './filters-bar.component.html',
})
export class FiltersBarComponent {
  private readonly store = inject(BoardStore);

  readonly statuses = ALL_STATUSES;
  readonly ramps = this.store.ramps;
  readonly filters = this.store.filters;
  readonly activeCount = this.store.activeFilters;

  /** На мобільному фільтри ховаються у шторку (STW-30). */
  readonly open = signal(false);

  readonly supplierQuery = computed(() => this.filters().supplierQuery);

  toggleDrawer(): void {
    this.open.update((v) => !v);
  }

  toggleRamp(rampId: string): void {
    const current = this.filters();
    const next = current.rampIds.includes(rampId)
      ? current.rampIds.filter((id) => id !== rampId)
      : [...current.rampIds, rampId];
    this.store.setFilters({ ...current, rampIds: next });
  }

  /** Скидає лише вибір рамп, не чіпаючи інші фільтри. */
  clearRamps(): void {
    this.store.setFilters({ ...this.filters(), rampIds: [] });
  }

  /** «Усі статуси» знімає і статуси, і дві окремі ознаки поруч із ними. */
  clearStatuses(): void {
    this.store.setFilters({
      ...this.filters(),
      statuses: [],
      onlyDelayed: false,
      onlyWalkIn: false,
    });
  }

  noStatusFilter(): boolean {
    const f = this.filters();
    return f.statuses.length === 0 && !f.onlyDelayed && !f.onlyWalkIn;
  }

  toggleStatus(status: BookingStatus): void {
    const current = this.filters();
    const next = current.statuses.includes(status)
      ? current.statuses.filter((s) => s !== status)
      : [...current.statuses, status];
    this.store.setFilters({ ...current, statuses: next });
  }

  toggleDelayed(): void {
    const current = this.filters();
    this.store.setFilters({ ...current, onlyDelayed: !current.onlyDelayed });
  }

  toggleWalkIn(): void {
    const current = this.filters();
    this.store.setFilters({ ...current, onlyWalkIn: !current.onlyWalkIn });
  }

  onSupplierQuery(value: string): void {
    this.store.setFilters({ ...this.filters(), supplierQuery: value });
  }

  clear(): void {
    this.store.clearFilters();
  }

  isRampActive(rampId: string): boolean {
    return this.filters().rampIds.includes(rampId);
  }

  isStatusActive(status: BookingStatus): boolean {
    return this.filters().statuses.includes(status);
  }
}
