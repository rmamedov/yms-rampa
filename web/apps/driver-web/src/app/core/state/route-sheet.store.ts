import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from '../data/driver.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { NetworkService } from '../offline/network.service';
import { ProblemMessageService } from '../http/problem-message.service';
import type {
  AvailableDate,
  DayRouteSheet,
  RoutePoint,
} from '../models/route-sheet.model';
import { kyivDateKey } from '../util/time.util';
import { environment } from '../../../environments/environment';

interface CachedSheet {
  readonly sheet: DayRouteSheet;
  /** Момент кешування, epoch ms. */
  readonly cachedAt: number;
}

/** Статуси, у яких точка вважається закритою (картка згортається). */
const CLOSED_STATUSES = new Set<RoutePoint['status']>([
  'completed',
  'cancelled',
  'no_show',
  'rejected',
]);

/** Статуси, які не додаються до підсумку палет. */
const NOT_COUNTED_STATUSES = new Set<RoutePoint['status']>([
  'cancelled',
  'no_show',
  'rejected',
]);

export function isClosedPoint(point: RoutePoint): boolean {
  return CLOSED_STATUSES.has(point.status);
}

/**
 * Індекс активної точки — найближча незавершена за часом слоту (DRV-16).
 * Повертає -1, якщо всі точки закриті.
 */
export function activePointIndex(points: readonly RoutePoint[]): number {
  return points.findIndex((p) => !isClosedPoint(p));
}

/**
 * Стан маршрутного листа водія.
 *
 * Стор ТІЛЬКИ читає: у контурі водія бекенд не має жодного маршруту, який
 * змінює бронювання (ані «На місці», ані orderId, ані затримки).
 */
@Injectable({ providedIn: 'root' })
export class RouteSheetStore {
  private readonly api = inject(DriverApi);
  private readonly storage = inject(LocalStorageService);
  private readonly network = inject(NetworkService);
  private readonly problems = inject(ProblemMessageService);

  private readonly sheetSignal = signal<DayRouteSheet | null>(null);
  private readonly datesSignal = signal<readonly AvailableDate[]>([]);
  private readonly selectedDateSignal = signal<string>(kyivDateKey());
  private readonly loadingSignal = signal(false);
  private readonly errorSignal = signal<string | null>(null);
  private readonly staleSignal = signal(false);
  private readonly cachedAtSignal = signal<number | null>(null);
  private readonly lastSyncSignal = signal<number | null>(null);

  readonly sheet = this.sheetSignal.asReadonly();
  readonly dates = this.datesSignal.asReadonly();
  readonly selectedDate = this.selectedDateSignal.asReadonly();
  readonly loading = this.loadingSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** Дані взяті з офлайн-кешу (DRV-33). */
  readonly stale = this.staleSignal.asReadonly();
  readonly cachedAt = this.cachedAtSignal.asReadonly();
  readonly lastSyncAt = this.lastSyncSignal.asReadonly();

  readonly points = computed<readonly RoutePoint[]>(
    () => this.sheetSignal()?.points ?? [],
  );
  readonly activeIndex = computed(() => activePointIndex(this.points()));
  readonly totalPallets = computed(() =>
    this.points()
      .filter((p) => !NOT_COUNTED_STATUSES.has(p.status))
      .reduce((sum, p) => sum + p.palletsCount, 0),
  );
  readonly isToday = computed(() => this.selectedDateSignal() === kyivDateKey());
  readonly hasOtherDates = computed(() => this.datesSignal().length > 0);

  private pollTimer: ReturnType<typeof setInterval> | null = null;

  /** Первинне завантаження: список дат + лист на сьогодні (DRV-12). */
  async initialize(): Promise<void> {
    this.selectedDateSignal.set(kyivDateKey());
    this.restoreFromCache();
    await this.loadDates();
    await this.load(this.selectedDateSignal());
  }

  async selectDate(date: string): Promise<void> {
    if (date === this.selectedDateSignal()) {
      return;
    }
    this.selectedDateSignal.set(date);
    await this.load(date);
  }

  async refresh(): Promise<void> {
    await this.loadDates();
    await this.load(this.selectedDateSignal(), { silent: true });
  }

  async load(date: string, options: { silent?: boolean } = {}): Promise<void> {
    if (!options.silent) {
      this.loadingSignal.set(true);
    }
    this.errorSignal.set(null);
    try {
      const sheet = await firstValueFrom(this.api.routeSheet(date));
      this.sheetSignal.set(sheet);
      this.staleSignal.set(false);
      this.lastSyncSignal.set(Date.now());
      this.network.setOnline(true);
      if (sheet && date === kyivDateKey()) {
        this.cache(sheet);
      }
    } catch (error) {
      this.handleLoadError(error, date);
    } finally {
      this.loadingSignal.set(false);
    }
  }

  private handleLoadError(error: unknown, date: string): void {
    if (this.problems.isNetworkError(error)) {
      this.network.setOnline(false);
      const cached = this.readCache();
      if (cached && cached.sheet.date === date) {
        this.sheetSignal.set(cached.sheet);
        this.cachedAtSignal.set(cached.cachedAt);
        this.staleSignal.set(true);
        this.errorSignal.set(null);
        return;
      }
    }
    this.errorSignal.set(this.problems.messageFor(error, 'sheet.loadError'));
  }

  private async loadDates(): Promise<void> {
    try {
      const dates = await firstValueFrom(this.api.availableDates());
      this.datesSignal.set(dates);
    } catch {
      // Перелік дат — допоміжні дані; без мережі лишаємо попередній.
    }
  }

  /** Полінг статусів раз на 30 с (RT-04). */
  startPolling(intervalMs: number = environment.pollIntervalMs): void {
    this.stopPolling();
    if (typeof setInterval !== 'function') {
      return;
    }
    this.pollTimer = setInterval(() => {
      void this.load(this.selectedDateSignal(), { silent: true });
    }, intervalMs);
  }

  stopPolling(): void {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private cache(sheet: DayRouteSheet): void {
    const payload: CachedSheet = { sheet, cachedAt: Date.now() };
    this.storage.write(STORAGE_KEYS.routeSheetCache, payload);
    this.cachedAtSignal.set(payload.cachedAt);
  }

  private readCache(): CachedSheet | null {
    const cached = this.storage.read<CachedSheet | null>(
      STORAGE_KEYS.routeSheetCache,
      null,
    );
    if (!cached?.sheet?.date) {
      return null;
    }
    return cached;
  }

  /** Показати кешований лист одразу при старті, до відповіді мережі (DRV-33). */
  private restoreFromCache(): void {
    const cached = this.readCache();
    if (cached && cached.sheet.date === this.selectedDateSignal()) {
      this.sheetSignal.set(cached.sheet);
      this.cachedAtSignal.set(cached.cachedAt);
      this.staleSignal.set(!this.network.online());
    }
  }

  /** Скидання стану при виході (DRV-09). */
  reset(): void {
    this.stopPolling();
    this.sheetSignal.set(null);
    this.datesSignal.set([]);
    this.errorSignal.set(null);
    this.staleSignal.set(false);
    this.cachedAtSignal.set(null);
    this.lastSyncSignal.set(null);
  }
}
