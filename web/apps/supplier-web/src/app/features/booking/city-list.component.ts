import {
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  computed,
  inject,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { CityCacheService } from '../../core/services/city-cache.service';
import { filterCities } from '../../core/util/search';
import type { CityItem } from '../../core/models/models';
import { ToastService } from '../../shared/ui/toast.service';

const SEARCH_DEBOUNCE_MS = 250;

@Component({
  selector: 'app-city-list',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe],
  templateUrl: './city-list.component.html',
  styleUrl: './city-list.component.scss',
})
export class CityListComponent implements OnDestroy {
  private readonly cities = inject(CityCacheService);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private timer: ReturnType<typeof setTimeout> | null = null;

  protected readonly all = signal<readonly CityItem[]>([]);
  protected readonly loading = signal(true);
  protected readonly rawQuery = signal('');
  protected readonly query = signal('');
  protected readonly visible = computed(() =>
    filterCities(this.all(), this.query()),
  );

  constructor() {
    this.cities.cities().subscribe({
      next: (list) => {
        this.all.set(list);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.toasts.problem(error);
      },
    });
  }

  /** SUP-CITY-03: debounce ≤ 300 мс, реакція від 1 символу. */
  protected onSearch(value: string): void {
    this.rawQuery.set(value);
    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => this.query.set(value), SEARCH_DEBOUNCE_MS);
  }

  protected countLabel(count: number): string {
    return this.i18n.storeCount(count);
  }

  ngOnDestroy(): void {
    if (this.timer) {
      clearTimeout(this.timer);
    }
  }
}
