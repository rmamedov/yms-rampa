import {
  EMPTY_FILTERS,
  activeFilterCount,
  applyFilters,
  computeDailyStats,
  computeRiskState,
  computeTimelineBounds,
  groupByRamp,
  placeOnTimeline,
  sortBySlotStart,
} from './board.util';
import { makeBooking } from '../testing/booking.factory';
import { Ramp } from '../models/store.model';

const RAMPS: Ramp[] = [
  { rampId: 'r1', name: 'Рампа 1', active: true },
  { rampId: 'r2', name: 'Рампа 2', active: true },
];

describe('Фільтри дошки (STW-23)', () => {
  const bookings = [
    makeBooking({ id: 'a', rampId: 'r1', status: 'booked' }),
    makeBooking({
      id: 'b',
      rampId: 'r2',
      status: 'arrived',
      supplierNameSnapshot: 'ПрАТ «Оболонь»',
    }),
    makeBooking({
      id: 'c',
      rampId: 'r1',
      status: 'completed',
      type: 'walk_in',
      supplierNameSnapshot: 'ФОП Гуменюк',
    }),
    makeBooking({
      id: 'd',
      rampId: 'r2',
      status: 'booked',
      delayed: {
        flag: true,
        reason: 'ramp_busy',
        eta: '2026-08-27T09:00:00.000Z',
        comment: null,
      },
    }),
  ];

  it('без фільтрів повертає всі бронювання', () => {
    expect(applyFilters(bookings, EMPTY_FILTERS)).toHaveLength(4);
    expect(activeFilterCount(EMPTY_FILTERS)).toBe(0);
  });

  it('комбінує рампу, статус і постачальника за логікою AND', () => {
    const result = applyFilters(bookings, {
      ...EMPTY_FILTERS,
      rampIds: ['r1'],
      statuses: ['booked', 'completed'],
      supplierQuery: 'гуменюк',
    });
    expect(result.map((b) => b.id)).toEqual(['c']);
  });

  it('фільтрує позапланові та із затримкою', () => {
    expect(
      applyFilters(bookings, { ...EMPTY_FILTERS, onlyWalkIn: true }).map(
        (b) => b.id,
      ),
    ).toEqual(['c']);
    expect(
      applyFilters(bookings, { ...EMPTY_FILTERS, onlyDelayed: true }).map(
        (b) => b.id,
      ),
    ).toEqual(['d']);
  });

  it('рахує кількість активних фільтрів для чипів', () => {
    expect(
      activeFilterCount({
        rampIds: ['r1'],
        statuses: ['booked'],
        supplierQuery: '  ',
        onlyDelayed: true,
        onlyWalkIn: false,
      }),
    ).toBe(3);
  });
});

describe('Денна зведена статистика (STW-24)', () => {
  it('рахує всі показники і середнє очікування по completed', () => {
    const bookings = [
      makeBooking({ id: '1', status: 'booked' }),
      makeBooking({
        id: '2',
        status: 'completed',
        arrivedAt: '2026-08-27T07:00:00.000Z',
        unloadingStartedAt: '2026-08-27T07:10:00.000Z',
        completedAt: '2026-08-27T07:40:00.000Z',
      }),
      makeBooking({
        id: '3',
        status: 'completed',
        arrivedAt: '2026-08-27T08:00:00.000Z',
        unloadingStartedAt: '2026-08-27T08:20:00.000Z',
        completedAt: '2026-08-27T08:50:00.000Z',
      }),
      makeBooking({ id: '4', status: 'unloading' }),
      makeBooking({ id: '5', status: 'no_show' }),
      makeBooking({ id: '6', status: 'rejected' }),
      makeBooking({ id: '7', status: 'arrived', type: 'walk_in' }),
    ];

    const stats = computeDailyStats(bookings);
    expect(stats.total).toBe(7);
    expect(stats.arrived).toBe(4); // arrived + unloading + completed
    expect(stats.completed).toBe(2);
    expect(stats.noShow).toBe(1);
    expect(stats.rejected).toBe(1);
    expect(stats.walkIn).toBe(1);
    expect(stats.avgWaitMinutes).toBe(15);
  });

  it('повертає null для середнього очікування без завершених', () => {
    expect(computeDailyStats([makeBooking()]).avgWaitMinutes).toBeNull();
  });
});

describe('Overrun і «під ризиком» (STW-40)', () => {
  const now = '2026-08-27T08:00:00.000Z';

  it('підсвічує рампу з перевищенням і наступні бронювання цієї рампи', () => {
    const bookings = [
      makeBooking({
        id: 'over',
        rampId: 'r1',
        status: 'unloading',
        slotStart: '2026-08-27T07:00:00.000Z',
        slotEnd: '2026-08-27T07:30:00.000Z',
      }),
      makeBooking({
        id: 'next1',
        rampId: 'r1',
        status: 'booked',
        slotStart: '2026-08-27T08:00:00.000Z',
        slotEnd: '2026-08-27T08:30:00.000Z',
      }),
      makeBooking({
        id: 'other-ramp',
        rampId: 'r2',
        status: 'booked',
        slotStart: '2026-08-27T08:00:00.000Z',
        slotEnd: '2026-08-27T08:30:00.000Z',
      }),
      makeBooking({
        id: 'earlier-done',
        rampId: 'r1',
        status: 'completed',
        slotStart: '2026-08-27T06:00:00.000Z',
        slotEnd: '2026-08-27T06:30:00.000Z',
      }),
    ];

    const risk = computeRiskState(bookings, now);
    expect(risk.overrunRampIds).toEqual(['r1']);
    expect(risk.overrunBookingIds).toEqual(['over']);
    expect(risk.overrunMinutes['over']).toBe(30);
    expect(risk.atRiskBookingIds).toEqual(['next1']);
  });

  it('знімає підсвічування після переходу в completed', () => {
    const bookings = [
      makeBooking({
        id: 'over',
        rampId: 'r1',
        status: 'completed',
        slotStart: '2026-08-27T07:00:00.000Z',
        slotEnd: '2026-08-27T07:30:00.000Z',
        completedAt: '2026-08-27T07:55:00.000Z',
      }),
      makeBooking({ id: 'next1', rampId: 'r1', status: 'booked' }),
    ];
    const risk = computeRiskState(bookings, now);
    expect(risk.overrunRampIds).toEqual([]);
    expect(risk.atRiskBookingIds).toEqual([]);
  });

  it('розвантаження в межах слоту не є overrun', () => {
    const risk = computeRiskState(
      [
        makeBooking({
          id: 'ok',
          status: 'unloading',
          slotStart: '2026-08-27T07:45:00.000Z',
          slotEnd: '2026-08-27T08:15:00.000Z',
        }),
      ],
      now,
    );
    expect(risk.overrunBookingIds).toEqual([]);
  });
});

describe('Групування і таймлайн (STW-06)', () => {
  it('розкладає бронювання по колонках рамп і сортує за часом слоту', () => {
    const bookings = [
      makeBooking({ id: 'late', rampId: 'r1', slotStart: '2026-08-27T09:00:00.000Z' }),
      makeBooking({ id: 'early', rampId: 'r1', slotStart: '2026-08-27T07:00:00.000Z' }),
      makeBooking({ id: 'other', rampId: 'r2' }),
    ];
    const columns = groupByRamp(bookings, RAMPS);
    expect(columns.map((c) => c.ramp.rampId)).toEqual(['r1', 'r2']);
    expect(columns[0].bookings.map((b) => b.id)).toEqual(['early', 'late']);
    expect(columns[1].bookings).toHaveLength(1);
  });

  it('мобільний список сортує всі картки за часом слоту', () => {
    const sorted = sortBySlotStart([
      makeBooking({ id: 'b', slotStart: '2026-08-27T10:00:00.000Z' }),
      makeBooking({ id: 'a', slotStart: '2026-08-27T06:00:00.000Z' }),
    ]);
    expect(sorted.map((b) => b.id)).toEqual(['a', 'b']);
  });

  it('обчислює межі таймлайну з вікон прийому', () => {
    expect(
      computeTimelineBounds([
        { from: '08:00', to: '13:00' },
        { from: '14:00', to: '20:00' },
      ]),
    ).toEqual({ startMinutes: 480, endMinutes: 1200 });
  });

  it('позиціонує картку за slotStart–slotEnd у відсотках', () => {
    const bounds = { startMinutes: 480, endMinutes: 1200 }; // 08:00–20:00, 720 хв
    // 10:00–10:30 за київським часом = 07:00–07:30 UTC улітку.
    const placement = placeOnTimeline(
      makeBooking({
        slotStart: '2026-08-27T07:00:00.000Z',
        slotEnd: '2026-08-27T07:30:00.000Z',
      }),
      bounds,
    );
    expect(placement.leftPercent).toBeCloseTo(16.67, 1);
    expect(placement.widthPercent).toBeCloseTo(4.17, 1);
  });
});
