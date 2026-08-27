import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { BookingApi } from '../../core/api/contracts';
import type { Booking } from '../../core/models/models';
import { ToastService } from '../../shared/ui/toast.service';
import { StatusBadgeComponent } from '../../shared/ui/status-badge.component';
import {
  KyivDatePipe,
  KyivTimePipe,
} from '../../shared/ui/datetime.pipes';
import { kyivDateIso } from '../../core/util/kyiv-time';

const HOME_LIMIT = 10;

@Component({
  selector: 'app-home',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    TranslatePipe,
    StatusBadgeComponent,
    KyivDatePipe,
    KyivTimePipe,
  ],
  templateUrl: './home.component.html',
  styleUrl: './home.component.scss',
})
export class HomeComponent {
  private readonly bookings = inject(BookingApi);
  private readonly toasts = inject(ToastService);

  protected readonly items = signal<readonly Booking[]>([]);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.bookings.upcoming(HOME_LIMIT).subscribe({
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

  protected sheetDate(booking: Booking): string {
    return kyivDateIso(new Date(booking.slotStart));
  }
}
