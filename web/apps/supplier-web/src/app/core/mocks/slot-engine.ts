import type {
  Ramp,
  ReceivingWindow,
  SlotCell,
  SlotGrid,
  SlotRow,
  SlotState,
} from '../models/models';
import { kyivOffsetMinutes, kyivToUtc } from '../util/kyiv-time';

/**
 * Обчислювана сітка слотів (SLOT-01, GRID-01).
 * Слоти не матеріалізуються: сітка будується з конфігурації магазину,
 * поверх якої накладаються блокування, резерви, бронювання та холди.
 * Пріоритет станів (SLOT-03): past → blocked → booked → held → reserved → available.
 */

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
  readonly mine: boolean;
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
}

/** Розклад резервів: вікно доби за конкретним постачальником. */
export interface EngineReserve {
  readonly rampId: string;
  readonly from: string;
  readonly to: string;
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

  const bookingIndex = new Map<string, EngineBooking>();
  for (const booking of input.bookings) {
    bookingIndex.set(`${booking.rampId}|${booking.slotStart}`, booking);
  }
  const holdIndex = new Set<string>();
  for (const hold of input.holds) {
    if (new Date(hold.expiresAt).getTime() > now.getTime()) {
      holdIndex.add(`${hold.rampId}|${hold.slotStart}`);
    }
  }

  const rows: SlotRow[] = [];
  const starts = slotStartsForWindows(store.windows, store.slotSizeMinutes);

  for (const startMin of starts) {
    const endMin = startMin + store.slotSizeMinutes;
    const slotStartMs = midnightUtcNaive + (startMin - offsetMinutes) * 60000;
    const slotStart = new Date(slotStartMs).toISOString();
    const slotEnd = new Date(
      slotStartMs + store.slotSizeMinutes * 60000,
    ).toISOString();
    const isPast = slotStartMs < leadEdge;

    const cells: SlotCell[] = store.ramps.map((ramp) => {
      const key = `${ramp.rampId}|${slotStart}`;
      const blocked = input.blocks.some(
        (block) =>
          (block.rampId === null || block.rampId === ramp.rampId) &&
          overlaps(startMin, endMin, block.from, block.to),
      );
      const booking = bookingIndex.get(key);
      const held = holdIndex.has(key);
      const reserve = input.reserves.find(
        (item) =>
          item.rampId === ramp.rampId &&
          overlaps(startMin, endMin, item.from, item.to),
      );

      let state: SlotState = 'available';
      let mine = false;
      let bookingId: string | undefined;

      if (isPast) {
        state = 'past';
      } else if (blocked) {
        state = 'blocked';
      } else if (booking) {
        state = 'booked';
        mine = booking.mine;
        bookingId = booking.mine ? booking.id : undefined;
      } else if (held) {
        state = 'held';
      } else if (reserve) {
        // GRID-04: власнику резерву слот показується як доступний з міткою.
        state = reserve.mine ? 'available' : 'reserved';
        mine = reserve.mine;
      }

      return { rampId: ramp.rampId, slotStart, slotEnd, state, mine, bookingId };
    });

    rows.push({ label: fromMinutes(startMin), slotStart, cells });
  }

  return {
    storeId: store.storeId,
    date,
    ramps: [...store.ramps],
    rows,
    maxVehicleWeightTons: store.maxVehicleWeightTons,
    slotSizeMinutes: store.slotSizeMinutes,
    leadTimeMinutes: store.leadTimeMinutes,
    bookingHorizonDays: store.bookingHorizonDays,
    now: now.toISOString(),
  };
}

/** Чи є у сітці хоч один слот, доступний для бронювання. */
export function hasAvailableSlot(grid: SlotGrid): boolean {
  return grid.rows.some((row) =>
    row.cells.some((cell) => cell.state === 'available'),
  );
}

/** Клікабельні лише available-слоти (SUP-SLOT-04). */
export function isSelectableState(state: SlotState): boolean {
  return state === 'available';
}
