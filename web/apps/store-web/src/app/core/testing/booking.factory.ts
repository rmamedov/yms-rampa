import { Booking } from '../models/booking.model';

/** Фабрика бронювань для тестів логіки — у формі відповіді booking-service. */
export function makeBooking(overrides: Partial<Booking> = {}): Booking {
  const base: Booking = {
    id: 'bk-1',
    type: 'scheduled',
    status: 'booked',
    storeId: 'store-1',
    store: {
      externalId: '1998',
      displayName: 'Сільпо №1998',
      city: 'Київ',
      address: 'просп. Володимира Івасюка, 46',
    },
    rampId: 'r1',
    slotStart: '2026-08-27T07:00:00.000Z',
    slotEnd: '2026-08-27T07:30:00.000Z',
    localDate: '2026-08-27',
    localTime: '10:00',
    supplierId: 'sp-01',
    supplierName: 'ТОВ «Молокія»',
    vehicle: { plateNumber: 'AA1234BB', weightTons: 5, brand: 'MAN' },
    driverId: 'dr-01',
    orderId: 'ORD-1001',
    palletsCount: 26,
    delayed: { flag: false, reason: null, eta: null },
    arrivedAt: null,
    unloadingStartedAt: null,
    completedAt: null,
    cancelledAt: null,
    cancellation: null,
    rejectedAt: null,
    unloadedPalletsCount: null,
    partialUnload: null,
    rescheduleOf: null,
    routeSheetId: null,
    createdBy: 'sp-01',
    createdAt: '2026-08-26T05:00:00.000Z',
    updatedAt: '2026-08-27T06:00:00.000Z',
    statusHistory: [],
  };
  return { ...base, ...overrides };
}
