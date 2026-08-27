import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { DriverApi, VehicleApi } from '../../core/api/contracts';
import type { Driver, DriverCreated, Vehicle } from '../../core/models/models';
import {
  normalizePhone,
  validatePhone,
} from '../../core/util/validation';
import { ToastService } from '../../shared/ui/toast.service';
import { ModalComponent } from '../../shared/ui/modal.component';

@Component({
  selector: 'app-drivers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, ModalComponent],
  templateUrl: './drivers.component.html',
  styleUrl: './drivers.component.scss',
})
export class DriversComponent {
  private readonly api = inject(DriverApi);
  private readonly vehiclesApi = inject(VehicleApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);

  protected readonly drivers = signal<readonly Driver[]>([]);
  protected readonly vehicles = signal<readonly Vehicle[]>([]);
  protected readonly loading = signal(true);
  protected readonly formOpen = signal(false);
  protected readonly saving = signal(false);
  protected readonly credentials = signal<DriverCreated | null>(null);
  protected readonly confirmRegenerate = signal<Driver | null>(null);
  protected readonly confirmDeactivate = signal<Driver | null>(null);

  protected readonly phone = signal('');
  protected readonly firstName = signal('');
  protected readonly lastName = signal('');
  protected readonly vehicleId = signal('');

  protected readonly phoneError = computed(() => validatePhone(this.phone()));
  protected readonly nameError = computed(() =>
    this.firstName().trim() ? null : 'validation.firstNameRequired',
  );
  protected readonly lastNameError = computed(() =>
    this.lastName().trim() ? null : 'validation.lastNameRequired',
  );
  protected readonly canSave = computed(
    () =>
      !this.saving() &&
      !this.phoneError() &&
      !this.nameError() &&
      !this.lastNameError(),
  );
  protected readonly activeVehicles = computed(() =>
    this.vehicles().filter((vehicle) => vehicle.active),
  );

  constructor() {
    this.load();
    this.vehiclesApi.list().subscribe({
      next: (list) => this.vehicles.set(list),
      error: () => undefined,
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.api.list().subscribe({
      next: (list) => {
        this.drivers.set(list);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.toasts.problem(error);
      },
    });
  }

  protected openCreate(): void {
    this.phone.set('+380');
    this.firstName.set('');
    this.lastName.set('');
    this.vehicleId.set('');
    this.formOpen.set(true);
  }

  protected save(): void {
    if (!this.canSave()) {
      return;
    }
    this.saving.set(true);
    this.api
      .create({
        phone: normalizePhone(this.phone()),
        firstName: this.firstName().trim(),
        lastName: this.lastName().trim(),
        vehicleId: this.vehicleId() || undefined,
      })
      .subscribe({
        next: (created) => {
          this.saving.set(false);
          this.formOpen.set(false);
          this.credentials.set(created);
          this.toasts.success(this.i18n.t('drivers.created'));
          this.load();
        },
        error: (error: unknown) => {
          this.saving.set(false);
          this.toasts.problem(error);
        },
      });
  }

  protected regenerate(): void {
    const driver = this.confirmRegenerate();
    if (!driver) {
      return;
    }
    this.api.regeneratePassword(driver.id).subscribe({
      next: (created) => {
        this.confirmRegenerate.set(null);
        this.credentials.set(created);
        this.toasts.success(this.i18n.t('drivers.passwordRegenerated'));
      },
      error: (error: unknown) => {
        this.confirmRegenerate.set(null);
        this.toasts.problem(error);
      },
    });
  }

  protected toggleActive(driver: Driver): void {
    if (driver.active) {
      this.confirmDeactivate.set(driver);
      return;
    }
    this.applyActive(driver, true);
  }

  protected confirmDeactivation(): void {
    const driver = this.confirmDeactivate();
    if (driver) {
      this.applyActive(driver, false);
    }
    this.confirmDeactivate.set(null);
  }

  private applyActive(driver: Driver, active: boolean): void {
    this.api.setActive(driver.id, active).subscribe({
      next: () => {
        this.toasts.success(
          this.i18n.t(active ? 'drivers.activated' : 'drivers.deactivated'),
        );
        this.load();
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  protected regenerateLabel(): string {
    const driver = this.confirmRegenerate();
    return driver
      ? this.i18n.t('drivers.regenerateConfirm', {
          name: `${driver.lastName} ${driver.firstName}`,
        })
      : '';
  }

  protected deactivateLabel(): string {
    const driver = this.confirmDeactivate();
    return driver
      ? this.i18n.t('drivers.deactivateConfirm', {
          name: `${driver.lastName} ${driver.firstName}`,
        })
      : '';
  }
}
