import { Booking } from '../models/booking.model';

/** Фабрика бронювань для тестів логіки. */
export function makeBooking(overrides: Partial<Booking> = {}): Booking {
  const base: Booking = {
    id: 'bk-1',
    type: 'scheduled',
    storeId: 'store-1',
    rampId: 'r1',
    slotStart: '2026-08-27T07:00:00.000Z',
    slotEnd: '2026-08-27T07:30:00.000Z',
    supplierId: 'sp-01',
    supplierNameSnapshot: 'ТОВ «Молокія»',
    vehicle: { plateNumber: 'AA1234BB', weightTons: 5, brand: 'MAN' },
    driver: {
      driverId: 'dr-01',
      fullName: 'Іван Коваленко',
      phone: '+380671234501',
    },
    orderId: 'ORD-1001',
    palletsCount: 26,
    status: 'booked',
    delayed: { flag: false, reason: null, eta: null, comment: null },
    arrivedAt: null,
    unloadingStartedAt: null,
    completedAt: null,
    cancelledAt: null,
    rejectedAt: null,
    unloadedPalletsCount: null,
    partialUnload: null,
    version: 1,
    updatedAt: '2026-08-27T06:00:00.000Z',
  };
  return { ...base, ...overrides };
}
