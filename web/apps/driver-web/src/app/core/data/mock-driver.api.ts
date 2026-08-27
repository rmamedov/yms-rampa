import { Injectable, inject } from '@angular/core';
import { Observable, defer, delay, of, throwError } from 'rxjs';
import { DriverApi } from './driver.api';
import { toDayRouteSheet } from './route-sheet.mapper';
import { MockBackend } from '../mock/mock-backend';
import { NetworkService } from '../offline/network.service';
import { ApiProblemError } from '../models/problem.model';
import type { DayRouteSheet } from '../models/route-sheet.model';

/** Штучна затримка мережі, щоб стани завантаження були видимі. */
const LATENCY_MS = 180;

@Injectable()
export class MockDriverApi extends DriverApi {
  private readonly backend = inject(MockBackend);
  private readonly network = inject(NetworkService);

  override routeSheet(date: string): Observable<DayRouteSheet | null> {
    // Той самий мапінг конверта, що й у HttpDriverApi — форма даних однакова.
    return this.wrap(() => toDayRouteSheet(this.backend.routeSheet(date)));
  }

  private wrap<T>(fn: () => T): Observable<T> {
    return defer(() => {
      // Мок імітує реальну мережу: в офлайні запит падає так само, як HTTP.
      if (!this.network.online()) {
        return throwError(
          () =>
            new ApiProblemError(0, {
              code: 'NETWORK_UNAVAILABLE',
              detail: 'Немає звʼязку із сервером',
            }),
        );
      }
      try {
        return of(fn()).pipe(delay(LATENCY_MS));
      } catch (error) {
        return throwError(() => error).pipe(delay(LATENCY_MS));
      }
    });
  }
}
