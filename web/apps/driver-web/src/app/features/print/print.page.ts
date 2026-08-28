import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from '../../core/data/driver.api';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import {
  rampLabel,
  type DayRouteSheet,
} from '../../core/models/route-sheet.model';
import { formatKyivDateTime, kyivDateKey } from '../../core/util/time.util';
import { formatPhone } from '../../core/util/phone.util';

/**
 * Друкована версія маршрутного листа (DRV-40, PRN-01/PRN-02).
 *
 * Склад полів обмежений тим, що віддає `GET /api/driver/v1/route-sheet`:
 * назви постачальника, імені водія, моделі й тоннажу авто та номера філії
 * у цьому контурі немає.
 */
@Component({
  selector: 'app-print-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './print.page.html',
  styleUrl: './print.page.scss',
})
export class PrintPage implements OnInit {
  private readonly api = inject(DriverApi);
  private readonly auth = inject(AuthService);

  /** Дата листа з URL (/print/:date). */
  readonly date = input<string>(kyivDateKey());

  protected readonly sheet = signal<DayRouteSheet | null>(null);
  protected readonly loading = signal(true);

  protected readonly generatedAt = formatKyivDateTime(Date.now());

  protected readonly rows = computed(() =>
    (this.sheet()?.points ?? [])
      .filter(
        (p) =>
          p.status !== 'cancelled' &&
          p.status !== 'no_show' &&
          p.status !== 'rejected',
      )
      .map((p) => ({
        time: p.localTime,
        storeName: p.storeName,
        city: p.city,
        address: p.address,
        // На папері має бути те саме, що на воротах, — номер або назва рампи.
        ramp: rampLabel(p),
        orderId: p.orderId ?? '—',
        pallets: p.palletsCount,
      })),
  );

  protected readonly totalPallets = computed(() =>
    this.rows().reduce((sum, r) => sum + r.pallets, 0),
  );

  /** У шапці — телефон водія (логін): імені бекенд не повертає. */
  protected readonly driverPhone = computed(() => {
    const profile = this.auth.profile();
    return profile ? formatPhone(profile.login) : '—';
  });

  /** Номери авто, задіяні в цьому листі. */
  protected readonly plateNumbers = computed(() =>
    [...new Set((this.sheet()?.points ?? []).map((p) => p.plateNumber))].join(', '),
  );

  protected readonly sheetCode = computed(
    () => this.sheet()?.routeSheetIds.join(', ') ?? '',
  );

  /** Дата листа у звичному для водія вигляді DD.MM.YYYY. */
  protected readonly displayDate = computed(() => {
    const date = this.sheet()?.date ?? this.date();
    const [y, m, d] = date.split('-');
    return d ? `${d}.${m}.${y}` : date;
  });

  async ngOnInit(): Promise<void> {
    try {
      const load = await firstValueFrom(this.api.routeSheet(this.date()));
      this.sheet.set(load.sheet);
    } finally {
      this.loading.set(false);
    }
  }

  protected print(): void {
    if (typeof window !== 'undefined' && typeof window.print === 'function') {
      window.print();
    }
  }
}
