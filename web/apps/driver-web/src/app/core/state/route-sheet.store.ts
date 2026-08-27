import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from '../data/driver.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { NetworkService } from '../offline/network.service';
import { ArrivalQueueService } from '../offline/arrival-queue.service';
import { GeolocationService } from '../geo/geolocation.service';
import { ProblemMessageService } from '../http/problem-message.service';
import { ApiProblemError } from '../models/problem.model';
import type {
  AvailableDate,
  DelayPayload,
  RoutePoint,
  RouteSheet,
} from '../models/route-sheet.model';
import { arriveWindowState, kyivDateKey } from '../util/time.util';
import { environment } from '../../../environments/environment';

interface CachedSheet {
  readonly sheet: RouteSheet;
  /** Момент кешування, epoch ms. */
  readonly cachedAt: number;
}

/** Статуси, у яких точка вважається закритою (картка згортається). */
const CLOSED_STATUSES = new Set(['completed', 'cancelled', 'no_show']);

/** Статуси, у яких водій може редагувати orderId (DRV-19). */
const ORDER_EDITABLE_STATUSES = new Set(['booked', 'arrived', 'unloading']);

export function canEditOrderId(point: RoutePoint): boolean {
  return ORDER_EDITABLE_STATUSES.has(point.status);
}

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

@Injectable({ providedIn: 'root' })
export class RouteSheetStore {
  private readonly api = inject(DriverApi);
  private readonly storage = inject(LocalStorageService);
  private readonly network = inject(NetworkService);
  private readonly queue = inject(ArrivalQueueService);
  private readonly geo = inject(GeolocationService);
  private readonly problems = inject(ProblemMessageService);

  private readonly sheetSignal = signal<RouteSheet | null>(null);
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
      .filter((p) => p.status !== 'cancelled' && p.status !== 'no_show')
      .reduce((sum, p) => sum + p.pallets, 0),
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
    await this.flushQueue();
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
      void this.poll();
    }, intervalMs);
  }

  stopPolling(): void {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private async poll(): Promise<void> {
    await this.load(this.selectedDateSignal(), { silent: true });
    await this.flushQueue();
  }

  /** Відправка накопичених офлайн-відміток. */
  async flushQueue(): Promise<void> {
    if (this.queue.pendingCount() === 0 || !this.network.online()) {
      return;
    }
    const result = await this.queue.flush();
    for (const point of result.sent) {
      this.patchPoint(point);
    }
    if (result.discarded.length > 0) {
      // Сервер уже має актуальний стан — перечитуємо лист (DRV-35).
      await this.load(this.selectedDateSignal(), { silent: true });
    }
  }

  /**
   * Відмітка «На місці». Фіксує ФАКТИЧНИЙ час натискання і, за наявності
   * дозволу, координати водія. Без мережі — кладе в чергу (DRV-34).
   */
  async markArrived(
    bookingId: string,
    pressedAt: string = new Date().toISOString(),
  ): Promise<{ ok: boolean; queued: boolean; message?: string }> {
    const coords = await this.geo.current();

    if (!this.network.online()) {
      this.queue.enqueue(bookingId, pressedAt, coords);
      return { ok: true, queued: true };
    }

    try {
      const point = await firstValueFrom(
        this.api.arrive(bookingId, {
          pressedAt,
          latitude: coords.latitude,
          longitude: coords.longitude,
        }),
      );
      this.patchPoint(point);
      return { ok: true, queued: false };
    } catch (error) {
      if (this.problems.isNetworkError(error)) {
        this.network.setOnline(false);
        this.queue.enqueue(bookingId, pressedAt, coords);
        return { ok: true, queued: true };
      }
      if (
        error instanceof ApiProblemError &&
        (error.is('BOOKING_ALREADY_ARRIVED') || error.is('BOOKING_CANCELLED'))
      ) {
        // Стан змінився в іншому клієнті — підтягуємо серверну версію (DRV-29, DRV-30).
        await this.load(this.selectedDateSignal(), { silent: true });
      }
      return {
        ok: false,
        queued: false,
        message: this.problems.messageFor(error, 'arrive.error'),
      };
    }
  }

  /** Збереження orderId водієм (DRV-17, DRV-18). */
  async saveOrderId(
    bookingId: string,
    orderId: string,
  ): Promise<{ ok: boolean; message?: string }> {
    try {
      const point = await firstValueFrom(this.api.setOrderId(bookingId, orderId.trim()));
      this.patchPoint(point);
      return { ok: true };
    } catch (error) {
      return { ok: false, message: this.problems.messageFor(error, 'error.generic') };
    }
  }

  /** Повідомлення про затримку (DRV-41). */
  async reportDelay(
    bookingId: string,
    payload: DelayPayload,
  ): Promise<{ ok: boolean; message?: string }> {
    try {
      const point = await firstValueFrom(this.api.setDelay(bookingId, payload));
      this.patchPoint(point);
      return { ok: true };
    } catch (error) {
      return {
        ok: false,
        message: this.problems.messageFor(error, 'delay.error.generic'),
      };
    }
  }

  /** Стан вікна відмітки для точки. */
  windowState(point: RoutePoint, now: number = Date.now()) {
    return arriveWindowState(point.slotStart, point.slotEnd, now);
  }

  private patchPoint(updated: RoutePoint): void {
    const sheet = this.sheetSignal();
    if (!sheet) {
      return;
    }
    const index = sheet.points.findIndex((p) => p.bookingId === updated.bookingId);
    if (index < 0) {
      return;
    }
    const points = [...sheet.points];
    points[index] = updated;
    const next: RouteSheet = { ...sheet, points };
    this.sheetSignal.set(next);
    if (next.date === kyivDateKey()) {
      this.cache(next);
    }
  }

  private cache(sheet: RouteSheet): void {
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
    this.queue.clear();
  }
}
