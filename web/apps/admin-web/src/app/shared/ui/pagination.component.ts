import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
} from '@angular/core';
import { PAGE_SIZES, PageSize } from '../../core/models';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { totalPages } from '../../core/utils/query-state.util';

/** UI-01: серверна пагінація з розміром сторінки 20/50/100. */
@Component({
  selector: 'app-pagination',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <div class="pagination">
      <span class="muted">{{ 'common.total' | t }}: {{ total() }}</span>
      <span class="spacer"></span>
      <label class="pagination-size">
        {{ 'common.page.size' | t }}
        <select
          [value]="pageSize()"
          (change)="onPageSize($event)"
          aria-label="page-size"
        >
          @for (size of sizes; track size) {
            <option [value]="size">{{ size }}</option>
          }
        </select>
      </label>
      <button
        type="button"
        class="btn btn-sm"
        [disabled]="page() <= 1"
        (click)="pageChange.emit(page() - 1)"
      >
        ‹
      </button>
      <span class="muted">{{
        'common.page.of' | t: { page: page(), pages: pages() }
      }}</span>
      <button
        type="button"
        class="btn btn-sm"
        [disabled]="page() >= pages()"
        (click)="pageChange.emit(page() + 1)"
      >
        ›
      </button>
    </div>
  `,
})
export class PaginationComponent {
  readonly page = input.required<number>();
  readonly pageSize = input.required<number>();
  readonly total = input.required<number>();

  readonly pageChange = output<number>();
  readonly pageSizeChange = output<PageSize>();

  protected readonly sizes = PAGE_SIZES;
  protected readonly pages = computed(() => totalPages(this.total(), this.pageSize()));

  protected onPageSize(event: Event): void {
    const value = Number((event.target as HTMLSelectElement).value) as PageSize;
    this.pageSizeChange.emit(value);
  }
}
