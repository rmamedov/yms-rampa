import type { Ramp, Slot, SlotGrid, SlotState } from '../models/models';
import { kyivOffsetMinutes, kyivToUtc, utcIso } from '../util/kyiv-time';

/**
 * Обчислювана сітка слотів (SLOT-01, GRID-01) — мок-двійник
 * booking-service\Domain\Slot\SlotGridGenerator.
 *
 * Слоти не матеріалізуються: сітка будується з конфігурації магазину,
 * поверх якої накладаються блокування, резерви, бронювання та холди.
 * Пріоритет станів (SLOT-03): past → blocked → booked → held → reserved → available.
 * Відповідь — ПЛОСКИЙ список слотів, точно як у бекенду.
 */

/** Вікно прийому у локальному часі магазину. */
export interface ReceivingWindow {
  readonly from: string;
  readonly to: string;
}

export interface SlotEngineStore {
  readonly storeId: string;
  readonly ramps: readonly Ramp[];
  readonly windows: readonly ReceivingWindow[];
  readonly slotSizeMinutes: number;
  readonly leadTimeMinutes: number;
  readonly bookingHorizonDays: number;
  readonly maxVehicleWeightTons: number;
}

export interface EngineBooking {
  readonly id: string;
  readonly rampId: string;
  readonly slotStart: string;
}

export interface EngineHold {
  readonly rampId: string;
  readonly slotStart: string;
  readonly expiresAt: string;
}

/** Блокування рампи (rampId=null — уся філія) у київському часі доби. */
export interface EngineBlock {
  readonly rampId: string | null;
  readonly from: string;
  readonly to: string;
  readonly reason?: string;
}

/** Розклад резервів: вікно доби за конкретним постачальником. */
export interface EngineReserve {
  readonly rampId: string;
  readonly from: string;
  readonly to: string;
  /** true — резерв належить постачальнику, який дивиться сітку. */
  readonly mine: boolean;
}

export interface SlotEngineInput {
  readonly store: SlotEngineStore;
  /** Дата в Europe/Kyiv, YYYY-MM-DD. */
  readonly date: string;
  readonly now: Date;
  readonly bookings: readonly EngineBooking[];
  readonly holds: readonly EngineHold[];
  readonly blocks: readonly EngineBlock[];
  readonly reserves: readonly EngineReserve[];
}

export function toMinutes(hm: string): number {
  const [h, m] = hm.split(':').map(Number);
  return h * 60 + m;
}

export function fromMinutes(total: number): string {
  const h = Math.floor(total / 60) % 24;
  const m = total % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/** Локальні початки слотів дня (у хвилинах від опівночі) за вікнами прийому. */
export function slotStartsForWindows(
  windows: readonly ReceivingWindow[],
  slotSizeMinutes: number,
): number[] {
  const starts = new Set<number>();
  for (const window of windows) {
    const from = toMinutes(window.from);
    const to = toMinutes(window.to);
    for (let t = from; t + slotSizeMinutes <= to; t += slotSizeMinutes) {
      starts.add(t);
    }
  }
  return [...starts].sort((a, b) => a - b);
}

function overlaps(
  startMin: number,
  endMin: number,
  from: string,
  to: string,
): boolean {
  return startMin < toMinutes(to) && endMin > toMinutes(from);
}

export function buildSlotGrid(input: SlotEngineInput): SlotGrid {
  const { store, date, now } = input;
  const [y, m, d] = date.split('-').map(Number);
  const midnightUtcNaive = Date.UTC(y, m - 1, d);
  // Один розрахунок зсуву на добу — DST-переходи в Україні відбуваються вночі.
  const offsetMinutes = kyivOffsetMinutes(kyivToUtc(date, '12:00'));
  const leadEdge = now.getTime() + store.leadTimeMinutes * 60000;

  const bookingIndex = new Set<string>();
  for (const booking of input.bookings) {
    bookingIndex.add(`${booking.rampId}|${booking.slotStart}`);
  }
  const holdIndex = new Set<string>();
  for (const hold of input.holds) {
    if (new Date(hold.expiresAt).getTime() > now.getTime()) {
      holdIndex.add(`${hold.rampId}|${hold.slotStart}`);
    }
  }

  const slots: Slot[] = [];

  for (const startMin of slotStartsForWindows(
    store.windows,
    store.slotSizeMinutes,
  )) {
    const endMin = startMin + store.slotSizeMinutes;
    const slotStartMs = midnightUtcNaive + (startMin - offsetMinutes) * 60000;
    const slotStart = utcIso(new Date(slotStartMs));
    const slotEnd = utcIso(
      new Date(slotStartMs + store.slotSizeMinutes * 60000),
    );
    const isPast = slotStartMs < leadEdge;

    for (const ramp of store.ramps) {
      const key = `${ramp.rampId}|${slotStart}`;
      const block = input.blocks.find(
        (item) =>
          (item.rampId === null || item.rampId === ramp.rampId) &&
          overlaps(startMin, endMin, item.from, item.to),
      );
      const reserve = input.reserves.find(
        (item) =>
          item.rampId === ramp.rampId &&
          overlaps(startMin, endMin, item.from, item.to),
      );

      let state: SlotState = 'available';
      if (isPast) {
        state = 'past';
      } else if (block) {
        state = 'blocked';
      } else if (bookingIndex.has(key)) {
        state = 'booked';
      } else if (holdIndex.has(key)) {
        state = 'held';
      } else if (reserve && !reserve.mine) {
        // GRID-04: власнику резерву слот лишається доступним з міткою.
        state = 'reserved';
      }

      const slot: Slot = {
        rampId: ramp.rampId,
        slotStart,
        slotEnd,
        localStart: fromMinutes(startMin),
        state,
        selectable: state === 'available',
        ...(reserve?.mine && state === 'available'
          ? { reservedForYou: true }
          : {}),
        ...(block?.reason ? { blockReason: block.reason } : {}),
      };
      slots.push(slot);
    }
  }

  return {
    storeId: store.storeId,
    date,
    maxVehicleWeightTons: store.maxVehicleWeightTons,
    slotSizeMinutes: store.slotSizeMinutes,
    leadTimeMinutes: store.leadTimeMinutes,
    now: utcIso(now),
    slots,
  };
}
