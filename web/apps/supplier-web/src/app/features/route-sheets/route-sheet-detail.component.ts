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
  RouteSheetApi,
  VehicleApi,
} from '../../core/api/contracts';
import type {
  Driver,
  RouteSheet,
  RouteSheetPoint,
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
import { KyivDayPipe } from '../../shared/ui/datetime.pipes';
import { TransferService } from '../../core/services/transfer.service';
import { BookingDraftService } from '../../core/services/booking-draft.service';
import {
  DriverDirectoryService,
  driverLabel,
} from '../../core/services/driver-directory.service';
import { summaryOf } from '../../core/services/route-sheets.service';
import { kyivDateIso } from '../../core/util/kyiv-time';

@Component({
  selector: 'app-route-sheet-detail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    TranslatePipe,
    StatusBadgeComponent,
    ModalComponent,
    KyivDayPipe,
  ],
  templateUrl: './route-sheet-detail.component.html',
  styleUrl: './route-sheet-detail.component.scss',
})
export class RouteSheetDetailComponent implements OnInit {
  private readonly api = inject(RouteSheetApi);
  private readonly bookings = inject(BookingApi);
  private readonly directory = inject(DriverDirectoryService);
  private readonly vehiclesApi = inject(VehicleApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);
  private readonly router = inject(Router);
  private readonly transfer = inject(TransferService);
  private readonly drafts = inject(BookingDraftService);

  readonly date = input.required<string>();

  protected readonly sheet = signal<RouteSheet | null>(null);
  protected readonly drivers = signal<readonly Driver[]>([]);
  protected readonly vehicles = signal<readonly Vehicle[]>([]);
  protected readonly loading = signal(true);
  protected readonly cancelling = signal<RouteSheetPoint | null>(null);
  protected readonly cancelReason = signal('');

  protected readonly activeDrivers = computed(() =>
    this.drivers().filter((driver) => driver.active),
  );

  protected readonly activeVehicles = computed(() =>
    this.vehicles().filter((vehicle) => vehicle.active),
  );

  /** Зведення листа (кількість точок, спільний водій) рахує клієнт. */
  protected readonly summary = computed(() => {
    const sheet = this.sheet();
    return sheet ? summaryOf(sheet, kyivDateIso(new Date())) : null;
  });

  ngOnInit(): void {
    this.load();
    this.directory.list().subscribe({
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

  protected driverName(driverId: string | null): string | null {
    return driverLabel(this.drivers(), driverId);
  }

  /** Держномер із точки листа — id авто в довіднику шукаємо за ним. */
  protected vehicleIdFor(point: RouteSheetPoint): string | null {
    return (
      this.vehicles().find((v) => v.plateNumber === point.plateNumber)?.id ??
      null
    );
  }

  protected canTransfer(point: RouteSheetPoint): boolean {
    return canTransfer(point.status);
  }

  protected canCancel(point: RouteSheetPoint): boolean {
    return canCancel(point.status);
  }

  protected canChangeDriver(point: RouteSheetPoint): boolean {
    return canChangeDriverOrVehicle(point.status);
  }

  protected lockedHint(point: RouteSheetPoint): string {
    return this.i18n.t('rs.lockedHint', {
      status: this.i18n.t(`status.${point.status}`),
    });
  }

  /**
   * SUP-RS-03: перенесення відкриває флоу вибору слота з предзаповненням.
   * Точка листа не містить storeId, тому бронювання дочитуємо цілком.
   */
  protected startTransfer(point: RouteSheetPoint): void {
    this.bookings.get(point.bookingId).subscribe({
      next: (booking) => {
        this.transfer.start(booking);
        this.drafts.save({
          vehicleId: this.vehicleIdFor(point),
          newVehicle: null,
          orderId: booking.orderId ?? '',
          palletsCount: booking.palletsCount,
        });
        void this.router.navigate(['/booking/stores', booking.storeId]);
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  protected confirmCancel(): void {
    const point = this.cancelling();
    if (!point) {
      return;
    }
    this.bookings.cancel(point.bookingId, this.cancelReason()).subscribe({
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

  /** RSHT-02: водій на окрему точку перекриває призначення листа. */
  protected assignPointDriver(point: RouteSheetPoint, driverId: string): void {
    this.api.assignDriverToBooking(point.bookingId, driverId || null).subscribe({
      next: () => {
        this.toasts.success(this.i18n.t('rs.driverAssigned'));
        this.load();
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  /** SUP-RS-07: заміна авто з повторною перевіркою тоннажу на сервері. */
  protected changeVehicle(point: RouteSheetPoint, vehicleId: string): void {
    const vehicle = this.vehicles().find((v) => v.id === vehicleId);
    if (!vehicle || vehicle.plateNumber === point.plateNumber) {
      return;
    }
    this.bookings
      .reassign(point.bookingId, {
        vehicle: {
          plateNumber: vehicle.plateNumber,
          weightTons: vehicle.weightTons,
          brand: vehicle.brand ?? undefined,
        },
      })
      .subscribe({
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

  /**
   * RSHT-02: водій на весь лист. Порожній вибір — це ДІЯ «зняти водія
   * з усього листа», а не «нічого не робити»: інакше список показував би
   * «Водія не призначено» на листі, з якого водія ніхто не знімав (ISSUE-18).
   *
   * Стан списку не чіпаємо руками — після відповіді лист перечитується,
   * тож на екрані завжди те, що є на сервері.
   */
  protected assignSheetDriver(driverId: string): void {
    const assigned = driverId || null;

    this.api.assignDriverToSheet(this.date(), assigned).subscribe({
      next: () => {
        this.toasts.success(
          this.i18n.t(assigned ? 'rs.driverAssigned' : 'rs.driverRemoved'),
        );
        this.load();
      },
      error: (error: unknown) => {
        this.toasts.problem(error);
        // Відмова бекенду не має лишати в списку вибір, якого не сталося.
        this.load();
      },
    });
  }
}
