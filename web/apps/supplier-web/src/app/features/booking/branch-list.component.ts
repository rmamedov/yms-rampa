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
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { CatalogApi } from '../../core/api/contracts';
import { filterBranches } from '../../core/util/search';
import type { BranchItem } from '../../core/models/models';
import { ToastService } from '../../shared/ui/toast.service';

const SEARCH_DEBOUNCE_MS = 250;

@Component({
  selector: 'app-branch-list',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe],
  templateUrl: './branch-list.component.html',
  styleUrl: './branch-list.component.scss',
})
export class BranchListComponent implements OnInit, OnDestroy {
  private readonly catalog = inject(CatalogApi);
  private readonly toasts = inject(ToastService);
  private timer: ReturnType<typeof setTimeout> | null = null;

  /** Параметр маршруту :city (withComponentInputBinding). */
  readonly city = input.required<string>();

  protected readonly all = signal<readonly BranchItem[]>([]);
  protected readonly loading = signal(true);
  protected readonly rawQuery = signal('');
  protected readonly query = signal('');
  protected readonly visible = computed(() =>
    filterBranches(this.all(), this.query()),
  );

  ngOnInit(): void {
    this.catalog.branches(this.city()).subscribe({
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

  protected onSearch(value: string): void {
    this.rawQuery.set(value);
    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => this.query.set(value), SEARCH_DEBOUNCE_MS);
  }

  ngOnDestroy(): void {
    if (this.timer) {
      clearTimeout(this.timer);
    }
  }
}
