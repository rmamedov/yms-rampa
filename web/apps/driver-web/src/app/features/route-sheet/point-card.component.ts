import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
} from '@angular/core';
import { LowerCasePipe } from '@angular/common';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { StatusBadgeComponent } from '../../shared/ui/status-badge.component';
import type { RoutePoint } from '../../core/models/route-sheet.model';
import { isClosedPoint } from '../../core/state/route-sheet.store';

/**
 * Картка точки маршруту.
 *
 * Показує рівно те, що віддає `GET /api/driver/v1/route-sheet`. Дій над
 * бронюванням тут немає: у контурі водія бекенд не має маршрутів для
 * відмітки «На місці», введення orderId і повідомлення про затримку.
 */
@Component({
  selector: 'app-point-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, LowerCasePipe, StatusBadgeComponent],
  templateUrl: './point-card.component.html',
  styleUrl: './point-card.component.scss',
  host: {
    '[class.active]': 'active()',
    '[class.closed]': 'closed()',
  },
})
export class PointCardComponent {
  readonly point = input.required<RoutePoint>();
  readonly active = input(false);

  readonly routeRequested = output<RoutePoint>();
  readonly routeOptionsRequested = output<RoutePoint>();

  protected readonly closed = computed(() => isClosedPoint(this.point()));
  /** Завершена точка згортається в компактний рядок (8.7). */
  protected readonly collapsed = computed(() => this.point().status === 'completed');

  protected onRouteClick(): void {
    this.routeRequested.emit(this.point());
  }

  /** Довге натискання відкриває вибір навігатора повторно (DRV-22). */
  protected onRouteContextMenu(event: Event): void {
    event.preventDefault();
    this.routeOptionsRequested.emit(this.point());
  }
}
