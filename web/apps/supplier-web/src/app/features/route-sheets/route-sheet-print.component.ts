import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  inject,
  input,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { RouteSheetApi } from '../../core/api/contracts';
import type { RouteSheetDetail } from '../../core/models/models';
import { ToastService } from '../../shared/ui/toast.service';
import { KyivDayPipe, KyivTimePipe } from '../../shared/ui/datetime.pipes';

@Component({
  selector: 'app-route-sheet-print',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, KyivDayPipe, KyivTimePipe],
  templateUrl: './route-sheet-print.component.html',
  styleUrl: './route-sheet-print.component.scss',
})
export class RouteSheetPrintComponent implements OnInit {
  private readonly api = inject(RouteSheetApi);
  private readonly toasts = inject(ToastService);

  readonly date = input.required<string>();
  protected readonly sheet = signal<RouteSheetDetail | null>(null);

  ngOnInit(): void {
    this.api.detail(this.date()).subscribe({
      next: (sheet) => this.sheet.set(sheet),
      error: (error: unknown) => this.toasts.problem(error),
    });
  }

  protected print(): void {
    window.print();
  }
}
