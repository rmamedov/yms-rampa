import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { VehicleApi } from '../../core/api/contracts';
import type { Vehicle } from '../../core/models/models';
import { filterVehicles } from '../../core/util/search';
import {
  isStandardPlate,
  normalizePlate,
  validatePlate,
  validateWeightTons,
} from '../../core/util/validation';
import { ToastService } from '../../shared/ui/toast.service';
import { ModalComponent } from '../../shared/ui/modal.component';

@Component({
  selector: 'app-vehicles',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, ModalComponent],
  templateUrl: './vehicles.component.html',
  styleUrl: './vehicles.component.scss',
})
export class VehiclesComponent {
  private readonly api = inject(VehicleApi);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(I18nService);

  protected readonly all = signal<readonly Vehicle[]>([]);
  protected readonly loading = signal(true);
  protected readonly query = signal('');
  protected readonly editing = signal<Vehicle | null>(null);
  protected readonly formOpen = signal(false);
  protected readonly removing = signal<Vehicle | null>(null);
  protected readonly saving = signal(false);

  protected readonly plate = signal('');
  protected readonly brand = signal('');
  protected readonly weight = signal<number | null>(null);

  protected readonly visible = computed(() =>
    filterVehicles(this.all(), this.query()),
  );

  protected readonly plateError = computed(() => validatePlate(this.plate()));
  protected readonly weightError = computed(() =>
    validateWeightTons(this.weight()),
  );
  protected readonly plateHint = computed(
    () => !this.plateError() && !isStandardPlate(this.plate()),
  );
  protected readonly duplicate = computed(() => {
    const plate = normalizePlate(this.plate());
    const editingId = this.editing()?.id;
    return (
      !!plate &&
      this.all().some((v) => v.plateNumber === plate && v.id !== editingId)
    );
  });
  protected readonly canSave = computed(
    () =>
      !this.saving() &&
      !this.plateError() &&
      !this.weightError() &&
      !this.duplicate(),
  );
  protected readonly weightText = computed(() =>
    this.weight() === null ? '' : String(this.weight()),
  );

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
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

  protected setWeight(raw: string): void {
    const value = Number(raw.replace(',', '.'));
    this.weight.set(raw.trim() === '' || Number.isNaN(value) ? null : value);
  }

  protected openCreate(): void {
    this.editing.set(null);
    this.plate.set('');
    this.brand.set('');
    this.weight.set(null);
    this.formOpen.set(true);
  }

  protected openEdit(vehicle: Vehicle): void {
    this.editing.set(vehicle);
    this.plate.set(vehicle.plateNumber);
    this.brand.set(vehicle.brand ?? '');
    this.weight.set(vehicle.weightTons);
    this.formOpen.set(true);
  }

  protected save(): void {
    if (!this.canSave()) {
      return;
    }
    this.saving.set(true);
    const input = {
      plateNumber: normalizePlate(this.plate()),
      brand: this.brand().trim() || undefined,
      weightTons: this.weight() ?? 0,
    };
    const editing = this.editing();
    const request = editing
      ? this.api.update(editing.id, input)
      : this.api.create(input);

    request.subscribe({
      next: () => {
        this.saving.set(false);
        this.formOpen.set(false);
        this.toasts.success(this.i18n.t('vehicles.saved'));
        this.load();
      },
      error: (error: unknown) => {
        this.saving.set(false);
        this.toasts.problem(error);
      },
    });
  }

  protected toggleActive(vehicle: Vehicle): void {
    this.api.setActive(vehicle.id, !vehicle.active).subscribe({
      next: () => {
        this.toasts.success(
          this.i18n.t(vehicle.active ? 'vehicles.deactivated' : 'vehicles.saved'),
        );
        this.load();
      },
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  protected confirmRemove(): void {
    const vehicle = this.removing();
    if (!vehicle) {
      return;
    }
    this.api.remove(vehicle.id).subscribe({
      next: () => {
        this.removing.set(null);
        this.toasts.success(this.i18n.t('vehicles.deleted'));
        this.load();
      },
      error: (error: unknown) => {
        this.removing.set(null);
        this.toasts.problem(error);
      },
    });
  }

  protected removeLabel(): string {
    const vehicle = this.removing();
    return vehicle
      ? this.i18n.t('vehicles.deleteConfirm', { plate: vehicle.plateNumber })
      : '';
  }

  protected formTitle(): string {
    return this.i18n.t(
      this.editing() ? 'vehicles.editTitle' : 'vehicles.createTitle',
    );
  }
}
