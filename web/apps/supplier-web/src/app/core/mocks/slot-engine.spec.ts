import {
  buildSlotGrid,
  slotStartsForWindows,
  type SlotEngineInput,
  type SlotEngineStore,
} from './slot-engine';
import {
  buildSlotRows,
  hasAvailableSlot,
  isSelectableState,
} from '../util/slot-matrix';
import { kyivToUtc, utcIso } from '../util/kyiv-time';

const DATE = '2026-03-12';

const store: SlotEngineStore = {
  storeId: 'st-1',
  ramps: [
    { rampId: 'r1', number: 1, name: '1' },
    { rampId: 'r2', number: 2, name: '2' },
  ],
  windows: [{ from: '08:00', to: '14:00' }],
  slotSizeMinutes: 30,
  leadTimeMinutes: 60,
  bookingHorizonDays: 14,
  maxVehicleWeightTons: 20,
};

function input(overrides: Partial<SlotEngineInput> = {}): SlotEngineInput {
  return {
    store,
    date: DATE,
    now: new Date('2026-03-11T09:00:00Z'),
    bookings: [],
    holds: [],
    blocks: [],
    reserves: [],
    ...overrides,
  };
}

function countState(grid: ReturnType<typeof buildSlotGrid>, state: string) {
  return grid.slots.filter((slot) => slot.state === state).length;
}

function rows(grid: ReturnType<typeof buildSlotGrid>) {
  return buildSlotRows(grid.slots, store.ramps);
}

describe('обчислювана сітка слотів (GRID-01, GRID-06)', () => {
  it('віддає плоский список слотів — 12 стартів × 2 рампи', () => {
    const grid = buildSlotGrid(input());
    expect(slotStartsForWindows(store.windows, 30)).toHaveLength(12);
    expect(grid.slots).toHaveLength(24);
    expect(countState(grid, 'available')).toBe(24);
    expect(grid.slots[0].localStart).toBe('08:00');
    expect(grid.slots[0].slotStart).toBe(utcIso(kyivToUtc(DATE, '08:00')));
  });

  it('матриця «час × рампа» будується клієнтом із плоского списку', () => {
    const matrix = rows(buildSlotGrid(input()));
    expect(matrix).toHaveLength(12);
    expect(matrix[0].label).toBe('08:00');
    expect(matrix[0].cells).toHaveLength(2);
    expect(matrix[0].cells[0]?.rampId).toBe('r1');
    expect(matrix[0].cells[1]?.rampId).toBe('r2');
  });

  it('блокування рампи 2 на 10:00–12:00 прибирає рівно 4 вільні слоти', () => {
    const grid = buildSlotGrid(
      input({ blocks: [{ rampId: 'r2', from: '10:00', to: '12:00' }] }),
    );
    expect(countState(grid, 'blocked')).toBe(4);
    expect(countState(grid, 'available')).toBe(20);
  });

  it('дотримується пріоритету станів past → blocked → booked → held', () => {
    const slotStart = utcIso(kyivToUtc(DATE, '09:00'));
    const grid = buildSlotGrid(
      input({
        bookings: [{ id: 'bk-1', rampId: 'r1', slotStart }],
        holds: [
          { rampId: 'r1', slotStart, expiresAt: '2026-03-12T23:00:00Z' },
          { rampId: 'r2', slotStart, expiresAt: '2026-03-12T23:00:00Z' },
        ],
      }),
    );
    const row = rows(grid).find((r) => r.label === '09:00');
    expect(row?.cells[0]?.state).toBe('booked');
    expect(row?.cells[0]?.selectable).toBe(false);
    expect(row?.cells[1]?.state).toBe('held');
  });

  it('позначає past усі слоти, що не проходять lead time', () => {
    const grid = buildSlotGrid(input({ now: new Date('2026-03-12T09:30:00Z') }));
    // 09:30 UTC = 11:30 Київ, lead time 60 хв → доступно з 12:30
    const past = rows(grid).filter((row) => row.label < '12:30');
    expect(
      past.every((row) => row.cells.every((c) => c?.state === 'past')),
    ).toBe(true);
    expect(countState(grid, 'available')).toBe(6);
  });

  it('резерв видно власнику як доступний з міткою reservedForYou (GRID-04)', () => {
    const mine = buildSlotGrid(
      input({
        reserves: [{ rampId: 'r1', from: '08:00', to: '10:00', mine: true }],
      }),
    );
    const foreign = buildSlotGrid(
      input({
        reserves: [{ rampId: 'r1', from: '08:00', to: '10:00', mine: false }],
      }),
    );
    const mineCell = rows(mine)[0].cells[0];
    const foreignCell = rows(foreign)[0].cells[0];

    expect(mineCell?.state).toBe('available');
    expect(mineCell?.reservedForYou).toBe(true);
    expect(mineCell?.mine).toBe(true);
    expect(isSelectableState(mineCell!.state)).toBe(true);

    expect(foreignCell?.state).toBe('reserved');
    expect(foreignCell?.mine).toBe(false);
    expect(isSelectableState(foreignCell!.state)).toBe(false);
  });

  it('клікабельні лише available-слоти (SUP-SLOT-04)', () => {
    expect(isSelectableState('available')).toBe(true);
    for (const state of ['held', 'booked', 'reserved', 'blocked', 'past']) {
      expect(isSelectableState(state as 'held')).toBe(false);
    }
  });

  it('повідомляє про відсутність вільних слотів для повністю заблокованої філії', () => {
    const grid = buildSlotGrid(
      input({ blocks: [{ rampId: null, from: '00:00', to: '23:59' }] }),
    );
    expect(hasAvailableSlot(grid.slots)).toBe(false);
    expect(hasAvailableSlot(buildSlotGrid(input()).slots)).toBe(true);
  });

  it('віддає разом із сіткою параметри філії та серверний now (GRID-05)', () => {
    const grid = buildSlotGrid(input());
    expect(grid.maxVehicleWeightTons).toBe(20);
    expect(grid.slotSizeMinutes).toBe(30);
    expect(grid.leadTimeMinutes).toBe(60);
    // Формат бекенду — секундна точність без мілісекунд (DATA-01).
    expect(grid.now).toBe('2026-03-11T09:00:00Z');
  });
});
