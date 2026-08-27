import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import type { Driver, UpcomingDelivery } from '../../core/models/models';
import { RouteSheetsService } from '../../core/services/route-sheets.service';
import {
  DriverDirectoryService,
  driverLabel,
} from '../../core/services/driver-directory.service';
import { ToastService } from '../../shared/ui/toast.service';
import { StatusBadgeComponent } from '../../shared/ui/status-badge.component';
import { KyivDayPipe } from '../../shared/ui/datetime.pipes';

const HOME_LIMIT = 10;

/**
 * SUP-HOME-01. Окремого маршруту «мої найближчі бронювання» у бекенді немає,
 * тому список збирається з маршрутних листів на найближчі дні
 * (див. RouteSheetsService).
 */
@Component({
  selector: 'app-home',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, StatusBadgeComponent, KyivDayPipe],
  templateUrl: './home.component.html',
  styleUrl: './home.component.scss',
})
export class HomeComponent {
  private readonly sheets = inject(RouteSheetsService);
  private readonly directory = inject(DriverDirectoryService);
  private readonly toasts = inject(ToastService);

  protected readonly items = signal<readonly UpcomingDelivery[]>([]);
  protected readonly drivers = signal<readonly Driver[]>([]);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);

  constructor() {
    this.load();
    this.directory.list().subscribe({
      next: (list) => this.drivers.set(list),
      error: () => undefined,
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.sheets.upcoming(HOME_LIMIT).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.failed.set(true);
        this.toasts.problem(error);
      },
    });
  }

  protected driverName(driverId: string | null): string | null {
    return driverLabel(this.drivers(), driverId);
  }
}
