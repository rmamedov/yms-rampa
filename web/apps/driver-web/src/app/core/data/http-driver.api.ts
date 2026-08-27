import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { DriverApi } from './driver.api';
import { toDayRouteSheet } from './route-sheet.mapper';
import type {
  DayRouteSheet,
  DriverRouteSheetResponse,
} from '../models/route-sheet.model';
import { environment } from '../../../environments/environment';

@Injectable()
export class HttpDriverApi extends DriverApi {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiBase;

  /**
   * `date` передається ЗАВЖДИ: без нього контролер бере поточну київську
   * дату, і клієнт із іншим часовим поясом отримав би чужий день.
   * Некоректний формат бекенд відхиляє з 422 VALIDATION_FAILED.
   */
  override routeSheet(date: string): Observable<DayRouteSheet | null> {
    return this.http
      .get<DriverRouteSheetResponse>(`${this.base}/route-sheet`, {
        params: new HttpParams().set('date', date),
      })
      .pipe(map(toDayRouteSheet));
  }
}
