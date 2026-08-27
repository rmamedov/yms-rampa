import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { RouteSheetApi } from '../../core/api/contracts';
import type { Driver, RouteSheet } from '../../core/models/models';
import {
  DriverDirectoryService,
  findDriver,
} from '../../core/services/driver-directory.service';
import { summaryOf } from '../../core/services/route-sheets.service';
import { kyivDateIso } from '../../core/util/kyiv-time';
import { ToastService } from '../../shared/ui/toast.service';
import { KyivDayPipe } from '../../shared/ui/datetime.pipes';

@Component({
  selector: 'app-route-sheet-print',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, KyivDayPipe],
  templateUrl: './route-sheet-print.component.html',
  styleUrl: './route-sheet-print.component.scss',
})
export class RouteSheetPrintComponent implements OnInit {
  private readonly api = inject(RouteSheetApi);
  private readonly directory = inject(DriverDirectoryService);
  private readonly toasts = inject(ToastService);

  readonly date = input.required<string>();
  protected readonly sheet = signal<RouteSheet | null>(null);
  protected readonly drivers = signal<readonly Driver[]>([]);

  /** Водій листа — з довідника partner-service за driverId точок. */
  protected readonly driver = computed(() => {
    const sheet = this.sheet();
    if (!sheet) {
      return null;
    }
    const summary = summaryOf(sheet, kyivDateIso(new Date()));
    return findDriver(this.drivers(), summary.driverId);
  });

  ngOnInit(): void {
    this.api.detail(this.date()).subscribe({
      next: (sheet) => this.sheet.set(sheet),
      error: (error: unknown) => this.toasts.problem(error),
    });
    this.directory.list().subscribe({
      next: (list) => this.drivers.set(list),
      error: () => undefined,
    });
  }

  protected print(): void {
    window.print();
  }
}
