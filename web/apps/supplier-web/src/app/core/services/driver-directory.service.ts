import { Injectable, inject } from '@angular/core';
import { Observable, shareReplay } from 'rxjs';
import { DriverApi } from '../api/contracts';
import type { Driver } from '../models/models';

/**
 * booking-service зберігає в бронюваннях і маршрутних листах лише `driverId`
 * (див. RouteSheetService::printView) — імʼя і телефон водія кабінет
 * збагачує сам із довідника partner-service. Довідник кешується на час
 * життя вкладки, бо його читає майже кожен екран.
 */
@Injectable({ providedIn: 'root' })
export class DriverDirectoryService {
  private readonly api = inject(DriverApi);
  private cache: Observable<Driver[]> | null = null;

  list(): Observable<Driver[]> {
    this.cache ??= this.api.list().pipe(shareReplay({ bufferSize: 1, refCount: false }));
    return this.cache;
  }

  invalidate(): void {
    this.cache = null;
  }
}

export function findDriver(
  drivers: readonly Driver[],
  driverId: string | null,
): Driver | null {
  if (!driverId) {
    return null;
  }
  return drivers.find((driver) => driver.id === driverId) ?? null;
}

/** «Прізвище Імʼя» або null, якщо водія не призначено / немає в довіднику. */
export function driverLabel(
  drivers: readonly Driver[],
  driverId: string | null,
): string | null {
  const driver = findDriver(drivers, driverId);
  return driver ? `${driver.lastName} ${driver.firstName}` : null;
}
