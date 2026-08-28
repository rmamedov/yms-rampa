import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from '../data/driver.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { NetworkService } from '../offline/network.service';
import { ArrivalQueueService } from '../offline/arrival-queue.service';
import { ProblemMessageService } from '../http/problem-message.service';
import { I18nService } from '../i18n/i18n.service';
import type {
  AvailableDate,
  DayRouteSheet,
  DelayState,
  RoutePoint,
} from '../models/route-sheet.model';
import {
  DELAY_REASON_REQUIRING_COMMENT,
  type BookingActionResult,
  type DelayReport,
} from '../models/booking-action.model';
import { kyivDateKey, toBackendIso } from '../util/time.util';
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
 * Стан маршрутного листа водія: читання листа плюс три дії контуру водія
 * («На місці», затримка, orderId) з офлайн-чергою для відмітки прибуття.
 */
@Injectable({ providedIn: 'root' })
export class RouteSheetStore {
  private readonly api = inject(DriverApi);
  private readonly storage = inject(LocalStorageService);
  private readonly network = inject(NetworkService);
  private readonly queue = inject(ArrivalQueueService);
  private readonly problems = inject(ProblemMessageService);
  private readonly i18n = inject(I18nService);

  private readonly sheetSignal = signal<DayRouteSheet | null>(null);
  private readonly datesSignal = signal<readonly AvailableDate[]>([]);
  private readonly selectedDateSignal = signal<string>(kyivDateKey());
  private readonly loadingSignal = signal(false);
  private readonly errorSignal = signal<string | null>(null);
  private readonly staleSignal = signal(false);
  private readonly cachedAtSignal = signal<number | null>(null);
  private readonly lastSyncSignal = signal<number | null>(null);
  private readonly pendingSignal = signal<readonly string[]>([]);
  private readonly actionErrorSignal = signal<string | null>(null);

  readonly sheet = this.sheetSignal.asReadonly();
  readonly dates = this.datesSignal.asReadonly();
  readonly selectedDate = this.selectedDateSignal.asReadonly();
  readonly loading = this.loadingSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** Дані взяті з офлайн-кешу (DRV-33). */
  readonly stale = this.staleSignal.asReadonly();
  readonly cachedAt = this.cachedAtSignal.asReadonly();
  readonly lastSyncAt = this.lastSyncSignal.asReadonly();
  /** Помилка останньої дії — показується окремо від помилки завантаження. */
  readonly actionError = this.actionErrorSignal.asReadonly();
  /** Скільки відміток «На місці» чекають на звʼязок. */
  readonly queuedArrivals = this.queue.size;

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
  private flushing = false;

  /** Дія над цією точкою вже виконується — кнопка блокується. */
  isPending(bookingId: string): boolean {
    return this.pendingSignal().includes(bookingId);
  }

  /** Відмітка «На місці» цієї точки лежить у черзі до появи звʼязку. */
  isQueued(bookingId: string): boolean {
    return this.queue.has(bookingId);
  }

  /**
   * Затримка точки.
   *
   * ДЖЕРЕЛО ІСТИНИ — сам лист: проєкція `GET /route-sheet` віддає `delayed`
   * (RouteSheetService::point()), тож банер переживає перезавантаження
   * сторінки і підтверджується полінгом, а не живе лише як відлуння власної
   * дії водія. Відповідь на дію лягає в ту саму точку — див. applyResult().
   */
  delayOf(bookingId: string): DelayState | null {
    const delayed = this.pointOf(bookingId)?.delayed;

    return delayed?.flag ? delayed : null;
  }

  /** Час фактичного прибуття з листа, UTC ISO 8601 або null. */
  arrivedAtOf(bookingId: string): string | null {
    return this.pointOf(bookingId)?.arrivedAt ?? null;
  }

  private pointOf(bookingId: string): RoutePoint | null {
    return (
      this.sheetSignal()?.points.find((p) => p.bookingId === bookingId) ?? null
    );
  }

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

  /**
   * Читання листа на дату.
   *
   * УСПІХ ≠ СВІЖІСТЬ. Без звʼязку service worker віддає збережену копію
   * звичайним 200 (DRV-33), і сам факт «запит не впав» нічого про мережу
   * не каже. Тому джерело відповіді береться з `load.fromCache`, а не
   * припускається: інакше екран показував би «Оновлено HH:MM» на добових
   * даних і жодного попередження (ISSUE-10).
   */
  async load(date: string, options: { silent?: boolean } = {}): Promise<void> {
    if (!options.silent) {
      this.loadingSignal.set(true);
    }
    this.errorSignal.set(null);
    try {
      const load = await firstValueFrom(this.api.routeSheet(date));
      this.sheetSignal.set(load.sheet);

      if (load.fromCache) {
        // Дані є, мережі немає: показуємо лист і чесно про це попереджаємо.
        // `lastSyncAt` не чіпаємо — з сервером цей запит не спілкувався.
        this.network.setOnline(false);
        this.staleSignal.set(true);
        if (load.cachedAt !== null) {
          this.cachedAtSignal.set(load.cachedAt);
        }
        return;
      }

      this.staleSignal.set(false);
      this.lastSyncSignal.set(Date.now());
      this.network.setOnline(true);
      if (load.sheet && date === kyivDateKey()) {
        this.cache(load.sheet);
      }
      // Звʼязок є — саме час віддати відкладені відмітки прибуття.
      await this.flushArrivalQueue();
    } catch (error) {
      this.handleLoadError(error, date);
    } finally {
      this.loadingSignal.set(false);
    }
  }

  // --- Дії водія --------------------------------------------------------------

  /**
   * «На місці» (ST-01). Без звʼязку відмітка стає в чергу з ФАКТИЧНИМ
   * часом натискання і піде на сервер сама, щойно звʼязок відновиться.
   */
  async markArrived(bookingId: string): Promise<void> {
    const occurredAt = toBackendIso();
    this.actionErrorSignal.set(null);

    if (!this.network.online()) {
      this.queue.enqueue(bookingId, occurredAt);
      return;
    }

    this.startPending(bookingId);
    try {
      const result = await firstValueFrom(
        this.api.markArrived(bookingId, occurredAt),
      );
      // Ідемпотентність: якщо магазин відмітив прибуття першим, бекенд
      // віддає поточний стан — для водія це успіх, а не збій.
      this.applyResult(result);
      this.queue.remove(bookingId);
    } catch (error) {
      if (this.problems.isNetworkError(error)) {
        this.network.setOnline(false);
        this.queue.enqueue(bookingId, occurredAt);
        return;
      }
      this.actionErrorSignal.set(this.actionMessage(error, 'point.arriveError'));
    } finally {
      this.stopPending(bookingId);
    }
  }

  /**
   * Віддає накопичені відмітки «На місці».
   *
   * Запис прибирається з черги не лише після успіху: якщо бекенд каже, що
   * відмітка вже не потрібна (магазин відмітив прибуття першим — 200
   * з поточним станом; точка пішла далі — 409; бронювання зникло — 404;
   * лист чужий — 403), тримати її в черзі немає сенсу і показувати водієві
   * помилку теж. У черзі лишається тільки те, що не дійшло через мережу.
   */
  async flushArrivalQueue(): Promise<void> {
    if (this.flushing || !this.network.online() || this.queue.isEmpty()) {
      return;
    }

    this.flushing = true;
    try {
      for (const item of this.queue.items()) {
        try {
          const result = await firstValueFrom(
            this.api.markArrived(item.bookingId, item.occurredAt),
          );
          this.applyResult(result);
          this.queue.remove(item.bookingId);
        } catch (error) {
          if (this.problems.isNetworkError(error)) {
            this.network.setOnline(false);
            return;
          }
          this.queue.remove(item.bookingId);
        }
      }
    } finally {
      this.flushing = false;
    }
  }

  /**
   * Повідомлення про затримку (DLY-01).
   *
   * Правила довідника продубльовані тут НЕ замість бекенду, а щоб водій
   * побачив підказку миттєво (зокрема в офлайні). Джерело істини лишається
   * серверним — його 422 показується як є.
   */
  async reportDelay(bookingId: string, report: DelayReport): Promise<boolean> {
    this.actionErrorSignal.set(null);

    const localProblem = validateDelay(report);
    if (localProblem) {
      this.actionErrorSignal.set(this.i18n.t(localProblem));
      return false;
    }

    this.startPending(bookingId);
    try {
      const result = await firstValueFrom(
        this.api.reportDelay(bookingId, report),
      );
      this.applyResult(result);
      return true;
    } catch (error) {
      this.actionErrorSignal.set(this.actionMessage(error, 'delay.error'));
      return false;
    } finally {
      this.stopPending(bookingId);
    }
  }

  /**
   * Дописування або зміна orderId. Після початку розвантаження бекенд
   * відповідає 422 з поясненням — воно й показується водієві.
   */
  async updateOrderId(
    bookingId: string,
    orderId: string | null,
  ): Promise<boolean> {
    this.actionErrorSignal.set(null);
    this.startPending(bookingId);
    try {
      const result = await firstValueFrom(
        this.api.updateOrderId(bookingId, orderId),
      );
      this.applyResult(result);
      return true;
    } catch (error) {
      this.actionErrorSignal.set(
        this.actionMessage(error, 'point.orderLockedError'),
      );
      return false;
    } finally {
      this.stopPending(bookingId);
    }
  }

  clearActionError(): void {
    this.actionErrorSignal.set(null);
  }

  /**
   * Текст помилки дії.
   *
   * 403 у контурі водія означає рівно одне — точка не з його маршрутного
   * листа (Booking::assertDriverOwnsPoint). Загальне «доступ закрито» тут
   * лише збиває з пантелику, тож для дій формулювання конкретніше.
   */
  private actionMessage(error: unknown, fallbackKey: string): string {
    return this.problems.codeOf(error) === 'ACCESS_DENIED'
      ? this.i18n.t('error.foreignBooking')
      : this.problems.messageFor(error, fallbackKey);
  }

  /**
   * Переносить відповідь дії у стан листа.
   *
   * Усі чотири поля (`status`, `orderId`, `delayed`, `arrivedAt`) є і в
   * проєкції листа, тож наступний полінг їх підтвердить, а не затре: тут
   * лише прибирається затримка до наступного читання. Знятий магазином
   * прапорець (ST-02, початок розвантаження) так само приїжджає відповіддю
   * з `flag: false` і гасить банер одразу.
   */
  private applyResult(result: BookingActionResult): void {
    const sheet = this.sheetSignal();

    if (!sheet?.points.some((p) => p.bookingId === result.bookingId)) {
      return;
    }

    const points = sheet.points.map((p) =>
      p.bookingId === result.bookingId
        ? {
            ...p,
            status: result.status,
            orderId: result.orderId,
            delayed: result.delayed,
            arrivedAt: result.arrivedAt,
          }
        : p,
    );
    const updated: DayRouteSheet = { ...sheet, points };
    this.sheetSignal.set(updated);

    if (updated.date === kyivDateKey()) {
      this.cache(updated);
    }
  }

  private startPending(bookingId: string): void {
    this.pendingSignal.update((ids) =>
      ids.includes(bookingId) ? ids : [...ids, bookingId],
    );
  }

  private stopPending(bookingId: string): void {
    this.pendingSignal.update((ids) => ids.filter((id) => id !== bookingId));
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

  /**
   * Скидання стану при виході (DRV-09).
   *
   * Черга прибуттів теж очищається: наступний водій на цьому телефоні
   * не має відправляти чужі відмітки.
   */
  reset(): void {
    this.stopPolling();
    this.sheetSignal.set(null);
    this.datesSignal.set([]);
    this.errorSignal.set(null);
    this.staleSignal.set(false);
    this.cachedAtSignal.set(null);
    this.lastSyncSignal.set(null);
    this.actionErrorSignal.set(null);
    this.pendingSignal.set([]);
    this.queue.clear();
  }
}

/**
 * Дзеркало правил Booking::setDelay, які можна перевірити без мережі.
 * Повертає ключ словника або null, якщо заперечень немає.
 */
export function validateDelay(
  report: DelayReport,
  now: number = Date.now(),
): string | null {
  const eta = Date.parse(report.eta);

  if (Number.isNaN(eta) || eta <= now) {
    return 'delay.etaInPast';
  }

  if (
    report.reason === DELAY_REASON_REQUIRING_COMMENT &&
    (report.comment ?? '').trim() === ''
  ) {
    return 'delay.commentRequired';
  }

  return null;
}
