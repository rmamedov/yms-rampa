import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { RouteSheetApi } from '../../core/api/contracts';
import type { RouteSheetSummary } from '../../core/models/models';
import { ToastService } from '../../shared/ui/toast.service';
import { KyivDayPipe } from '../../shared/ui/datetime.pipes';

@Component({
  selector: 'app-route-sheets',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, KyivDayPipe],
  templateUrl: './route-sheets.component.html',
  styleUrl: './route-sheets.component.scss',
})
export class RouteSheetsComponent {
  private readonly api = inject(RouteSheetApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);

  protected readonly all = signal<readonly RouteSheetSummary[]>([]);
  protected readonly loading = signal(true);
  protected readonly tab = signal<'upcoming' | 'archive'>('upcoming');

  protected readonly visible = computed(() => {
    const archive = this.tab() === 'archive';
    const list = this.all().filter((sheet) => sheet.archived === archive);
    return archive
      ? [...list].sort((a, b) => b.date.localeCompare(a.date))
      : [...list].sort((a, b) => a.date.localeCompare(b.date));
  });

  protected pointsLabel(count: number): string {
    return this.i18n.pointsCount(count);
  }

  constructor() {
    this.api.list().subscribe({
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
}
