import type { Ramp, Slot, SlotState } from '../models/models';

/**
 * Бекенд віддає сітку слотів ПЛОСКИМ списком (`slots[]`), без рядків і колонок.
 * Матрицю «час × рампа» будує клієнт: порядок колонок — порядок рамп філії,
 * рядків — початки слотів за зростанням.
 */

export interface SlotCell extends Slot {
  /** GRID-04: слот зарезервовано саме за цим постачальником. */
  readonly mine: boolean;
}

export interface SlotRow {
  /** Локальний підпис рядка, «HH:mm». */
  readonly label: string;
  readonly slotStart: string;
  /** null — на цій рампі слота о цій годині немає. */
  readonly cells: readonly (SlotCell | null)[];
}

export function toCell(slot: Slot): SlotCell {
  return { ...slot, mine: slot.reservedForYou === true };
}

export function buildSlotRows(
  slots: readonly Slot[],
  ramps: readonly Ramp[],
): SlotRow[] {
  const columns = ramps.length > 0 ? ramps.map((r) => r.rampId) : rampsOf(slots);
  const byStart = new Map<string, { label: string; cells: (SlotCell | null)[] }>();

  for (const slot of slots) {
    let row = byStart.get(slot.slotStart);
    if (!row) {
      row = {
        label: slot.localStart,
        cells: columns.map(() => null),
      };
      byStart.set(slot.slotStart, row);
    }
    const index = columns.indexOf(slot.rampId);
    if (index >= 0) {
      row.cells[index] = toCell(slot);
    }
  }

  return [...byStart.entries()]
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([slotStart, row]) => ({
      slotStart,
      label: row.label,
      cells: row.cells,
    }));
}

/** Порядок рамп, виведений із самої сітки — запасний варіант. */
function rampsOf(slots: readonly Slot[]): string[] {
  const seen: string[] = [];
  for (const slot of slots) {
    if (!seen.includes(slot.rampId)) {
      seen.push(slot.rampId);
    }
  }
  return seen;
}

/** Клікабельні лише available-слоти (SUP-SLOT-04, Slot::isSelectable). */
export function isSelectableState(state: SlotState): boolean {
  return state === 'available';
}

/** Чи є у сітці хоч один слот, доступний для бронювання. */
export function hasAvailableSlot(slots: readonly Slot[]): boolean {
  return slots.some((slot) => slot.selectable);
}
