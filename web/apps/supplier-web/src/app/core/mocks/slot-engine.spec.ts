import {
  buildSlotGrid,
  hasAvailableSlot,
  isSelectableState,
  slotStartsForWindows,
  type SlotEngineInput,
  type SlotEngineStore,
} from './slot-engine';
import { kyivToUtc } from '../util/kyiv-time';

const DATE = '2026-03-12';

const store: SlotEngineStore = {
  storeId: 'st-1',
  ramps: [
    { rampId: 'r1', name: '1' },
    { rampId: 'r2', name: '2' },
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
  return grid.rows
    .flatMap((row) => row.cells)
    .filter((cell) => cell.state === state).length;
}

describe('обчислювана сітка слотів (GRID-01, GRID-06)', () => {
  it('нарізає вікно 08:00–14:00 по 30 хв на 2 рампи — рівно 24 слоти', () => {
    const grid = buildSlotGrid(input());
    expect(slotStartsForWindows(store.windows, 30)).toHaveLength(12);
    expect(grid.rows).toHaveLength(12);
    expect(grid.rows.flatMap((row) => row.cells)).toHaveLength(24);
    expect(countState(grid, 'available')).toBe(24);
    expect(grid.rows[0].label).toBe('08:00');
    expect(grid.rows[0].slotStart).toBe(
      kyivToUtc(DATE, '08:00').toISOString(),
    );
  });

  it('блокування рампи 2 на 10:00–12:00 прибирає рівно 4 вільні слоти', () => {
    const grid = buildSlotGrid(
      input({ blocks: [{ rampId: 'r2', from: '10:00', to: '12:00' }] }),
    );
    expect(countState(grid, 'blocked')).toBe(4);
    expect(countState(grid, 'available')).toBe(20);
  });

  it('дотримується пріоритету станів past → blocked → booked → held', () => {
    const slotStart = kyivToUtc(DATE, '09:00').toISOString();
    const grid = buildSlotGrid(
      input({
        bookings: [{ id: 'bk-1', rampId: 'r1', slotStart, mine: true }],
        holds: [
          { rampId: 'r1', slotStart, expiresAt: '2026-03-12T23:00:00Z' },
          {
            rampId: 'r2',
            slotStart,
            expiresAt: '2026-03-12T23:00:00Z',
          },
        ],
      }),
    );
    const row = grid.rows.find((r) => r.label === '09:00');
    expect(row?.cells[0].state).toBe('booked');
    expect(row?.cells[0].mine).toBe(true);
    expect(row?.cells[0].bookingId).toBe('bk-1');
    expect(row?.cells[1].state).toBe('held');
  });

  it('позначає past усі слоти, що не проходять lead time', () => {
    const grid = buildSlotGrid(
      input({ now: new Date('2026-03-12T09:30:00Z') }),
    );
    // 09:30 UTC = 11:30 Київ, lead time 60 хв → доступно з 12:30
    const past = grid.rows.filter((row) => row.label < '12:30');
    expect(past.every((row) => row.cells.every((c) => c.state === 'past'))).toBe(
      true,
    );
    expect(countState(grid, 'available')).toBe(6);
  });

  it('резерв видно власнику як доступний, іншим — як недоступний (GRID-04)', () => {
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
    const mineCell = mine.rows[0].cells[0];
    const foreignCell = foreign.rows[0].cells[0];

    expect(mineCell.state).toBe('available');
    expect(mineCell.mine).toBe(true);
    expect(isSelectableState(mineCell.state)).toBe(true);

    expect(foreignCell.state).toBe('reserved');
    expect(isSelectableState(foreignCell.state)).toBe(false);
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
    expect(hasAvailableSlot(grid)).toBe(false);
    expect(hasAvailableSlot(buildSlotGrid(input()))).toBe(true);
  });

  it('віддає разом із сіткою параметри філії та серверний now (GRID-05)', () => {
    const grid = buildSlotGrid(input());
    expect(grid.maxVehicleWeightTons).toBe(20);
    expect(grid.slotSizeMinutes).toBe(30);
    expect(grid.leadTimeMinutes).toBe(60);
    expect(grid.now).toBe('2026-03-11T09:00:00.000Z');
  });
});
