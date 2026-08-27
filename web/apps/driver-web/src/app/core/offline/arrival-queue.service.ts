import { Injectable, computed, inject, signal } from '@angular/core';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';

/**
 * Відкладена відмітка «На місці».
 *
 * `occurredAt` — момент, коли водій НАТИСНУВ кнопку, а не момент відправки.
 * Саме він потрібен магазину: між натисканням у зоні без покриття
 * і появою звʼязку можуть минути десятки хвилин.
 */
export interface QueuedArrival {
  readonly bookingId: string;
  /** UTC ISO 8601 у формі бекенду (`Y-m-d\TH:i:s\Z`). */
  readonly occurredAt: string;
}

/**
 * Черга відміток «На місці», зроблених без звʼязку (DRV-33).
 *
 * Переживає перезапуск застосунку — лежить у localStorage, бо водій
 * цілком може закрити вкладку в дорозі й відкрити її вже на місці.
 *
 * Черга — це НЕ журнал: на одне бронювання не більше одного запису,
 * і при повторному натисканні зберігається ПЕРШИЙ час натискання.
 */
@Injectable({ providedIn: 'root' })
export class ArrivalQueueService {
  private readonly storage = inject(LocalStorageService);

  private readonly itemsSignal = signal<readonly QueuedArrival[]>(this.read());

  readonly items = this.itemsSignal.asReadonly();
  readonly size = computed(() => this.itemsSignal().length);
  readonly isEmpty = computed(() => this.itemsSignal().length === 0);

  has(bookingId: string): boolean {
    return this.itemsSignal().some((item) => item.bookingId === bookingId);
  }

  occurredAt(bookingId: string): string | null {
    return (
      this.itemsSignal().find((item) => item.bookingId === bookingId)
        ?.occurredAt ?? null
    );
  }

  enqueue(bookingId: string, occurredAt: string): void {
    if (this.has(bookingId)) {
      // Повторне натискання не зсуває фактичний час прибуття вперед.
      return;
    }
    this.commit([...this.itemsSignal(), { bookingId, occurredAt }]);
  }

  remove(bookingId: string): void {
    const rest = this.itemsSignal().filter((i) => i.bookingId !== bookingId);
    if (rest.length !== this.itemsSignal().length) {
      this.commit(rest);
    }
  }

  clear(): void {
    this.commit([]);
  }

  private commit(items: readonly QueuedArrival[]): void {
    this.itemsSignal.set(items);
    if (items.length === 0) {
      this.storage.remove(STORAGE_KEYS.arrivalQueue);
      return;
    }
    this.storage.write(STORAGE_KEYS.arrivalQueue, items);
  }

  /** Побитий або чужий вміст ключа не має ламати старт застосунку. */
  private read(): readonly QueuedArrival[] {
    const raw = this.storage.read<unknown>(STORAGE_KEYS.arrivalQueue, []);

    if (!Array.isArray(raw)) {
      return [];
    }

    return raw.filter(
      (item): item is QueuedArrival =>
        !!item &&
        typeof item === 'object' &&
        typeof (item as QueuedArrival).bookingId === 'string' &&
        typeof (item as QueuedArrival).occurredAt === 'string',
    );
  }
}
