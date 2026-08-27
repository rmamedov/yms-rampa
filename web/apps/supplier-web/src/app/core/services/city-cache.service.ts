import { Injectable, inject } from '@angular/core';
import { Observable, of, tap } from 'rxjs';
import { CatalogApi } from '../api/contracts';
import type { CityItem } from '../models/models';

/** SUP-CITY-04: список міст кешується на клієнті не довше 10 хв. */
export const CITY_CACHE_TTL_MS = 10 * 60 * 1000;

@Injectable({ providedIn: 'root' })
export class CityCacheService {
  private readonly api = inject(CatalogApi);
  private cache: { at: number; data: CityItem[] } | null = null;

  cities(): Observable<CityItem[]> {
    const cached = this.cache;
    if (cached && this.now() - cached.at < CITY_CACHE_TTL_MS) {
      return of(cached.data);
    }
    return this.api
      .cities()
      .pipe(tap((data) => (this.cache = { at: this.now(), data })));
  }

  invalidate(): void {
    this.cache = null;
  }

  /** Винесено окремо, щоб час можна було підмінити в тестах. */
  protected now(): number {
    return Date.now();
  }
}
