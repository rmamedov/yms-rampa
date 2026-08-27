import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from '../data/driver.api';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import { ApiProblemError } from '../models/problem.model';
import type { RoutePoint } from '../models/route-sheet.model';

/**
 * Черга офлайн-відміток «На місці» (DRV-34, DRV-35).
 *
 * Ключова вимога: у запиті на сервер передається ФАКТИЧНИЙ час натискання
 * кнопки водієм (`pressedAt`), а не час доставки запиту — саме за ним
 * booking-service обчислює прапорець `delayed`.
 */
export interface QueuedArrival {
  readonly bookingId: string;
  /** Фактичний час натискання кнопки водієм, UTC ISO 8601. */
  readonly pressedAt: string;
  readonly latitude: number | null;
  readonly longitude: number | null;
  /** Кількість невдалих спроб доставки (для діагностики). */
  readonly attempts: number;
}

export interface FlushResult {
  readonly sent: readonly RoutePoint[];
  /** Відкинуті тихо: сервер уже має цей або пізніший стан (ідемпотентність). */
  readonly discarded: readonly string[];
  /** Залишилися в черзі (мережа недоступна або 5xx). */
  readonly retained: readonly string[];
}

@Injectable({ providedIn: 'root' })
export class ArrivalQueueService {
  private readonly api = inject(DriverApi);
  private readonly storage = inject(LocalStorageService);

  private readonly itemsSignal = signal<readonly QueuedArrival[]>(this.load());

  readonly items = this.itemsSignal.asReadonly();
  readonly pendingCount = computed(() => this.itemsSignal().length);
  readonly flushing = signal(false);

  /** Чи є для цієї точки невідправлена відмітка. */
  isQueued(bookingId: string): boolean {
    return this.itemsSignal().some((i) => i.bookingId === bookingId);
  }

  queuedFor(bookingId: string): QueuedArrival | undefined {
    return this.itemsSignal().find((i) => i.bookingId === bookingId);
  }

  /**
   * Кладе відмітку в чергу. Повторний enqueue для того самого bookingId
   * не створює дубля і НЕ змінює зафіксований час натискання.
   */
  enqueue(
    bookingId: string,
    pressedAt: string,
    coords?: { latitude: number | null; longitude: number | null },
  ): QueuedArrival {
    const existing = this.queuedFor(bookingId);
    if (existing) {
      return existing;
    }
    const item: QueuedArrival = {
      bookingId,
      pressedAt,
      latitude: coords?.latitude ?? null,
      longitude: coords?.longitude ?? null,
      attempts: 0,
    };
    this.commit([...this.itemsSignal(), item]);
    return item;
  }

  remove(bookingId: string): void {
    this.commit(this.itemsSignal().filter((i) => i.bookingId !== bookingId));
  }

  clear(): void {
    this.commit([]);
  }

  /**
   * Намагається відправити всю чергу. Викликається при відновленні мережі
   * та вручну (pull-to-refresh, старт застосунку).
   */
  async flush(): Promise<FlushResult> {
    if (this.flushing()) {
      return { sent: [], discarded: [], retained: this.itemsSignal().map((i) => i.bookingId) };
    }
    this.flushing.set(true);
    const sent: RoutePoint[] = [];
    const discarded: string[] = [];
    const retained: QueuedArrival[] = [];
    try {
      for (const item of this.itemsSignal()) {
        try {
          const point = await firstValueFrom(
            this.api.arrive(item.bookingId, {
              pressedAt: item.pressedAt,
              latitude: item.latitude,
              longitude: item.longitude,
            }),
          );
          sent.push(point);
        } catch (error) {
          if (isRetryable(error)) {
            retained.push({ ...item, attempts: item.attempts + 1 });
          } else {
            // Магазин уже відмітив прибуття / точку скасовано —
            // черга очищається тихо (DRV-35).
            discarded.push(item.bookingId);
          }
        }
      }
      this.commit(retained);
    } finally {
      this.flushing.set(false);
    }
    return {
      sent,
      discarded,
      retained: retained.map((i) => i.bookingId),
    };
  }

  private commit(items: readonly QueuedArrival[]): void {
    this.itemsSignal.set(items);
    this.storage.write(STORAGE_KEYS.arrivalQueue, items);
  }

  private load(): readonly QueuedArrival[] {
    const raw = this.storage.read<unknown>(STORAGE_KEYS.arrivalQueue, []);
    if (!Array.isArray(raw)) {
      return [];
    }
    return raw.filter(isQueuedArrival);
  }
}

function isQueuedArrival(value: unknown): value is QueuedArrival {
  if (!value || typeof value !== 'object') {
    return false;
  }
  const v = value as Record<string, unknown>;
  return (
    typeof v['bookingId'] === 'string' &&
    typeof v['pressedAt'] === 'string' &&
    !Number.isNaN(Date.parse(v['pressedAt']))
  );
}

/**
 * Повторювати варто лише мережеві збої та 5xx/429.
 * Будь-яка інша 4xx означає, що сервер свідомо відхилив відмітку —
 * тримати її в черзі назавжди немає сенсу.
 */
export function isRetryable(error: unknown): boolean {
  if (error instanceof ApiProblemError) {
    if (error.status === 0 || error.status === 408 || error.status === 429) {
      return true;
    }
    return error.status >= 500;
  }
  // Невідома помилка (напр. TypeError під час fetch) — вважаємо мережевою.
  return true;
}
