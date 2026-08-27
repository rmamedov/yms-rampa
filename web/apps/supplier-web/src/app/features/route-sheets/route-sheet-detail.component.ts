import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import {
  BookingApi,
  DriverApi,
  RouteSheetApi,
} from '../../core/api/contracts';
import { VehicleApi } from '../../core/api/contracts';
import type {
  Booking,
  Driver,
  RouteSheetDetail,
  Vehicle,
} from '../../core/models/models';
import {
  canCancel,
  canChangeDriverOrVehicle,
  canTransfer,
} from '../../core/util/booking-rules';
import { ToastService } from '../../shared/ui/toast.service';
import { StatusBadgeComponent } from '../../shared/ui/status-badge.component';
import { ModalComponent } from '../../shared/ui/modal.component';
import { KyivDayPipe, KyivTimePipe } from '../../shared/ui/datetime.pipes';
import { TransferService } from '../../core/services/transfer.service';
import { BookingDraftService } from '../../core/services/booking-draft.service';

@Component({
  selector: 'app-route-sheet-detail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    TranslatePipe,
    StatusBadgeComponent,
    ModalComponent,
    KyivDayPipe,
    KyivTimePipe,
  ],
  templateUrl: './route-sheet-detail.component.html',
  styleUrl: './route-sheet-detail.component.scss',
})
export class RouteSheetDetailComponent implements OnInit {
  private readonly api = inject(RouteSheetApi);
  private readonly bookings = inject(BookingApi);
  private readonly driversApi = inject(DriverApi);
  private readonly vehiclesApi = inject(VehicleApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly router = inject(Router);
  private readonly transfer = inject(TransferService);
  private readonly drafts = inject(BookingDraftService);

  readonly date = input.required<string>();

  protected readonly sheet = signal<RouteSheetDetail | null>(null);
  protected readonly drivers = signal<readonly Driver[]>([]);
  protected readonly loading = signal(true);
  protected readonly cancelling = signal<Booking | null>(null);
  protected readonly cancelReason = signal('');

  protected readonly vehicles = signal<readonly Vehicle[]>([]);

  protected readonly activeDrivers = computed(() =>
    this.drivers().filter((driver) => driver.active),
  );

  protected readonly activeVehicles = computed(() =>
    this.vehicles().filter((vehicle) => vehicle.active),
  );

  ngOnInit(): void {
    this.load();
    this.driversApi.list().subscribe({
      next: (list) => this.drivers.set(list),
      error: (error: unknown) => this.toasts.problem(error),
    });
    this.vehiclesApi.list().subscribe({
      next: (list) => this.vehicles.set(list),
      error: () => undefined,
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.api.detail(this.date()).subscribe({
      next: (sheet) => {
        this.sheet.set(sheet);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.toasts.problem(error);
      },
    });
  }

  protected pointsLabel(count: number): string {
    return this.i18n.pointsCount(count);
  }

  protected canTransfer(booking: Booking): boolean {
    return canTransfer(booking.status);
  }

  protected canCancel(booking: Booking): boolean {
    return canCancel(booking.status);
  }

  protected canChangeDriver(booking: Booking): boolean {
    return canChangeDriverOrVehicle(booking.status);
  }

  protected lockedHint(booking: Booking): string {
    return this.i18n.t('rs.lockedHint', {
      status: this.i18n.t(`status.${booking.status}`),
    });
  }

  /** SUP-RS-03: перенесення відкриває флоу вибору слота з предзаповненням. */
  protected startTransfer(booking: Booking): void {
    this.transfer.start(booking);
    this.drafts.save({
      vehicleId: booking.vehicle.vehicleId ?? null,
      newVehicle: null,
      orderId: booking.orderId ?? '',
      palletsCount: booking.palletsCount,
    });
    void this.router.navigate(['/booking/stores', booking.storeId]);
  }

  protected confirmCancel(): void {
    const booking = this.cancelling();
    if (!booking) {
      return;
    }
    this.bookings.cancel(booking.id, this.cancelReason()).subscribe({
      next: () => {
        this.cancelling.set(null);
        this.cancelReason.set('');
        this.toasts.success(this.i18n.t('rs.cancelled'));
        this.load();
      },
      error: (error: unknown) => {
        this.cancelling.set(null);
        this.toasts.problem(error);
      },
    });
  }

  protected assignPointDriver(booking: Booking, driverId: string): void {
    this.bookings.assignDriver(booking.id, driverId || null).subscribe({
      next: () => {
        this.toasts.success(this.i18n.t('rs.driverAssigned'));
        this.load();
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  /** SUP-RS-07: заміна авто з повторною перевіркою тоннажу на сервері. */
  protected changeVehicle(booking: Booking, vehicleId: string): void {
    if (!vehicleId || vehicleId === booking.vehicle.vehicleId) {
      return;
    }
    this.bookings.changeVehicle(booking.id, vehicleId).subscribe({
      next: () => {
        this.toasts.success(this.i18n.t('rs.vehicleChanged'));
        this.load();
      },
      error: (error: unknown) => {
        this.toasts.problem(error);
        this.load();
      },
    });
  }

  protected assignSheetDriver(driverId: string): void {
    this.api.assignDriver(this.date(), driverId || null).subscribe({
      next: (sheet) => {
        this.sheet.set(sheet);
        this.toasts.success(this.i18n.t('rs.driverAssigned'));
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }
}
