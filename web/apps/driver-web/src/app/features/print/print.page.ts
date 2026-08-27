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
import { TranslatePipe } from '../../core/i18n/i18n.service';
import type { RouteSheet } from '../../core/models/route-sheet.model';
import {
  formatKyivDateTime,
  formatSlotRange,
  kyivDateKey,
} from '../../core/util/time.util';
import { formatPhone } from '../../core/util/phone.util';

/**
 * Друкована версія маршрутного листа (DRV-40, PRN-01/PRN-02).
 * Той самий склад полів, що й у друкованій формі supplier-web.
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

  /** Дата листа з URL (/print/:date). */
  readonly date = input<string>(kyivDateKey());

  protected readonly sheet = signal<RouteSheet | null>(null);
  protected readonly loading = signal(true);

  protected readonly generatedAt = formatKyivDateTime(Date.now());

  protected readonly rows = computed(() =>
    (this.sheet()?.points ?? [])
      .filter((p) => p.status !== 'cancelled' && p.status !== 'no_show')
      .map((p) => ({
        time: formatSlotRange(p.slotStart, p.slotEnd),
        externalId: p.store.externalId,
        city: p.store.city,
        address: p.store.address,
        ramp: p.rampNumber,
        orderId: p.orderId ?? '—',
        pallets: p.pallets,
      })),
  );

  protected readonly totalPallets = computed(() =>
    this.rows().reduce((sum, r) => sum + r.pallets, 0),
  );

  protected readonly driverPhone = computed(() =>
    formatPhone(this.sheet()?.driverPhone ?? ''),
  );

  /** Дата листа у звичному для водія вигляді DD.MM.YYYY. */
  protected readonly displayDate = computed(() => {
    const date = this.sheet()?.date ?? this.date();
    const [y, m, d] = date.split('-');
    return d ? `${d}.${m}.${y}` : date;
  });

  async ngOnInit(): Promise<void> {
    try {
      const sheet = await firstValueFrom(this.api.routeSheet(this.date()));
      this.sheet.set(sheet);
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
