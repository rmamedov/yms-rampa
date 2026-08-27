import { Booking, StoreConfig } from '../models';
import {
  canSaveConfig,
  detectConflicts,
  unresolvedCount,
} from './conflicts.util';
import { emptyReceivingWindows } from './store-config.util';

const MONDAY = '2026-09-07';
const TUESDAY = '2026-09-08';

function config(overrides: Partial<StoreConfig> = {}): StoreConfig {
  return {
    slotSizeMinutes: 30,
    ramps: [
      {
        id: 'r1',
        number: 1,
        name: 'Основна',
        enabled: true,
        disabledFrom: null,
        hasBookings: true,
      },
    ],
    maxVehicleWeightTons: 20,
    leadTimeHours: 4,
    bookingHorizonDays: 21,
    receivingWindows: emptyReceivingWindows().map((w) =>
      w.dayOfWeek <= 5
        ? { dayOfWeek: w.dayOfWeek, intervals: [{ from: '08:00', to: '12:00' }] }
        : w,
    ),
    exceptions: [],
    reservedRules: [],
    slotBlocks: [],
    ...overrides,
  };
}

function booking(overrides: Partial<Booking> = {}): Booking {
  return {
    id: 'bk-1',
    storeId: 'st-1',
    supplierId: 'sup-1',
    supplierName: 'ТОВ «Молочний Дім»',
    supplierPhone: '+380671110001',
    date: TUESDAY,
    startTime: '08:30',
    rampId: 'r1',
    vehiclePlate: 'AA1234BB',
    vehicleWeightTons: 12,
    orderId: 'ORD-1',
    status: 'booked',
    ...overrides,
  };
}

describe('STC-62 — виявлення конфліктів конфігурації', () => {
  it('без змін конфліктів немає', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config(),
      effectiveFrom: MONDAY,
    });
    expect(conflicts).toEqual([]);
  });

  it('STC-31: авто, важче за новий ліміт, потрапляє в конфлікти', () => {
    const conflicts = detectConflicts({
      bookings: [booking({ vehicleWeightTons: 24 })],
      nextConfig: config({ maxVehicleWeightTons: 20 }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts).toHaveLength(1);
    expect(conflicts[0].reason).toBe('weight_limit');
    expect(conflicts[0].booking.supplierPhone).toBe('+380671110001');
  });

  it('вимкнена рампа дає конфлікт ramp_disabled', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config({
        ramps: [
          {
            id: 'r1',
            number: 1,
            name: null,
            enabled: false,
            disabledFrom: MONDAY,
            hasBookings: true,
          },
        ],
      }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts[0].reason).toBe('ramp_disabled');
  });

  it('прибране вікно прийому дає конфлікт no_window', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config({ receivingWindows: emptyReceivingWindows() }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts[0].reason).toBe('no_window');
  });

  it('зміна розміру слоту зсуває сітку — slot_grid_shift', () => {
    const conflicts = detectConflicts({
      bookings: [booking({ startTime: '08:30' })],
      nextConfig: config({ slotSizeMinutes: 60 }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts[0].reason).toBe('slot_grid_shift');
  });

  it('STC-51: бронювання в діапазоні блокування — конфлікт, а не автоскасування', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config({
        slotBlocks: [
          {
            id: 'blk-1',
            date: TUESDAY,
            from: '08:00',
            to: '10:00',
            rampIds: [],
            reason: 'Інвентаризація',
            active: true,
            createdAt: '2026-09-01T00:00:00.000Z',
          },
        ],
      }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts[0].reason).toBe('blocked_range');
    expect(conflicts[0].booking.status).toBe('booked');
  });

  it('STC-43: слот, зарезервований іншому постачальнику', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config({
        reservedRules: [
          {
            id: 'res-1',
            supplierId: 'sup-99',
            dayOfWeek: 2,
            date: null,
            slotStartTime: '08:30',
            rampId: 'r1',
            validFrom: '2026-09-01',
            validTo: null,
            active: true,
          },
        ],
      }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts[0].reason).toBe('reserved_for_other');
  });

  it('резерв на того самого постачальника конфліктом не є', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config({
        reservedRules: [
          {
            id: 'res-1',
            supplierId: 'sup-1',
            dayOfWeek: 2,
            date: null,
            slotStartTime: '08:30',
            rampId: 'r1',
            validFrom: '2026-09-01',
            validTo: null,
            active: true,
          },
        ],
      }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts).toEqual([]);
  });

  it('STC-60: бронювання до дати X живуть за старою конфігурацією', () => {
    const conflicts = detectConflicts({
      bookings: [booking({ date: MONDAY, vehicleWeightTons: 30 })],
      nextConfig: config({ maxVehicleWeightTons: 10 }),
      effectiveFrom: TUESDAY,
    });
    expect(conflicts).toEqual([]);
  });

  it('скасовані та виконані бронювання не конфліктують', () => {
    const conflicts = detectConflicts({
      bookings: [
        booking({ id: 'b1', status: 'cancelled', vehicleWeightTons: 40 }),
        booking({ id: 'b2', status: 'completed', vehicleWeightTons: 40 }),
      ],
      nextConfig: config({ maxVehicleWeightTons: 5 }),
      effectiveFrom: MONDAY,
    });
    expect(conflicts).toEqual([]);
  });

  it('STC-05: переведення в paused виводить активні бронювання', () => {
    const conflicts = detectConflicts({
      bookings: [booking()],
      nextConfig: config(),
      effectiveFrom: MONDAY,
      nextYmsStatus: 'paused',
    });
    expect(conflicts[0].reason).toBe('store_paused');
  });
});

describe('STC-64 — збереження блокується до розвʼязання конфліктів', () => {
  const conflicts = detectConflicts({
    bookings: [
      booking({ id: 'b1', vehicleWeightTons: 30 }),
      booking({ id: 'b2', vehicleWeightTons: 35 }),
    ],
    nextConfig: config({ maxVehicleWeightTons: 20 }),
    effectiveFrom: MONDAY,
  });

  it('без рішень зберегти не можна', () => {
    expect(conflicts).toHaveLength(2);
    expect(unresolvedCount(conflicts, [])).toBe(2);
    expect(canSaveConfig(conflicts, [])).toBe(false);
  });

  it('часткове розвʼязання не розблоковує збереження', () => {
    const decisions = [{ conflictId: conflicts[0].id, resolution: 'keep' as const }];
    expect(unresolvedCount(conflicts, decisions)).toBe(1);
    expect(canSaveConfig(conflicts, decisions)).toBe(false);
  });

  it('рішення по кожному конфлікту розблоковує збереження', () => {
    const decisions = conflicts.map((c) => ({
      conflictId: c.id,
      resolution: 'cancel_notify' as const,
    }));
    expect(unresolvedCount(conflicts, decisions)).toBe(0);
    expect(canSaveConfig(conflicts, decisions)).toBe(true);
  });
});
