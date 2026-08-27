import { MockBackend } from './mock-backend';
import { ERROR_CODES, ApiProblemError } from '../api/problem';
import { addDays, kyivDateIso } from '../util/kyiv-time';
import { environment } from '../../../environments/environment';
import type { NetworkSettings, SlotKey } from '../models/models';

const NOW = new Date('2026-03-12T07:00:00Z');
const TODAY = kyivDateIso(NOW);

function backendAt(settings: Partial<NetworkSettings> = {}): MockBackend {
  return new MockBackend(() => NOW, settings);
}

interface Candidate extends SlotKey {
  maxVehicleWeightTons: number;
}

/** Перший вільний слот у першій київській філії з вільними слотами. */
function firstAvailable(backend: MockBackend, date = TODAY): Candidate {
  for (const branch of backend.branches('Київ')) {
    if (!branch.hasFreeSlots) {
      continue;
    }
    const grid = backend.slots(branch.storeId, date);
    for (const row of grid.rows) {
      for (const cell of row.cells) {
        if (cell.state === 'available' && !cell.mine) {
          return {
            storeId: branch.storeId,
            rampId: cell.rampId,
            slotStart: cell.slotStart,
            maxVehicleWeightTons: grid.maxVehicleWeightTons,
          };
        }
      }
    }
  }
  throw new Error('У фікстурі немає жодного вільного слота');
}

function problemCode(action: () => unknown): string {
  try {
    action();
  } catch (error) {
    return (error as ApiProblemError).problem.code;
  }
  throw new Error('Очікувалась помилка, але виклик завершився успішно');
}

describe('довідник міст і філій (SUP-CITY-01, SUP-BR-01)', () => {
  it('показує лише активні та дозволені постачальнику філії', () => {
    const backend = backendAt();
    const cities = backend.cities();
    expect(cities.length).toBeGreaterThan(10);
    expect(cities.every((city) => city.city.trim().length > 0)).toBe(true);
    expect(cities.every((city) => city.storeCount > 0)).toBe(true);

    const kyiv = cities.find((city) => city.city === 'Київ');
    expect(kyiv?.storeCount).toBe(backend.branches('Київ').length);
    expect(
      backend.branches('Київ').every((b) => b.ymsStatus === 'active'),
    ).toBe(true);
  });

  it('сортує міста за українською абеткою', () => {
    const cities = backendAt().cities().map((item) => item.city);
    const sorted = [...cities].sort((a, b) => a.localeCompare(b, 'uk'));
    expect(cities).toEqual(sorted);
  });

  it('відмовляє в доступі до недозволеної філії (403 SUPPLIER_NOT_ALLOWED)', () => {
    const backend = backendAt();
    const forbidden = backend
      .allStores()
      .find((store) => !store.allowedForSupplier);
    expect(forbidden).toBeDefined();
    expect(problemCode(() => backend.branch(forbidden!.storeId))).toBe(
      ERROR_CODES.supplierNotAllowed,
    );
  });
});

describe('сітка слотів і горизонт (GRID-03)', () => {
  it('віддає 422 DATE_OUT_OF_HORIZON для дати поза горизонтом', () => {
    const backend = backendAt();
    const store = backend.branches('Київ')[0];
    expect(
      problemCode(() => backend.slots(store.storeId, addDays(TODAY, 60))),
    ).toBe(ERROR_CODES.dateOutOfHorizon);
  });

  it('не віддає слоти на вчорашню дату', () => {
    const backend = backendAt();
    const store = backend.branches('Київ')[0];
    expect(
      problemCode(() => backend.slots(store.storeId, addDays(TODAY, -1))),
    ).toBe(ERROR_CODES.dateOutOfHorizon);
  });
});

describe('холди слота (HOLD-01, HOLD-03)', () => {
  it('дозволяє лише одну активну hold на слот — другий отримує 409 SLOT_HELD', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    expect(hold.holdToken).toBeTruthy();
    expect(problemCode(() => backend.hold(slot))).toBe(ERROR_CODES.slotHeld);
  });

  it('показує зайнятий холдом слот у сітці як held', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const slot = firstAvailable(backend, date);
    backend.hold(slot);
    const grid = backend.slots(slot.storeId, date);
    const cell = grid.rows
      .flatMap((row) => row.cells)
      .find(
        (item) =>
          item.rampId === slot.rampId && item.slotStart === slot.slotStart,
      );
    expect(cell?.state).toBe('held');
  });

  it('звільняє слот після release', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    backend.release(hold.holdToken);
    expect(backend.hold(slot).holdToken).toBeTruthy();
  });

  it('продовжує hold heartbeat-ом, але не далі за holdMaxMinutes', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    const extended = backend.heartbeat(hold.holdToken);
    expect(new Date(extended.expiresAt).getTime()).toBe(
      NOW.getTime() + 5 * 60000,
    );
    expect(new Date(extended.maxUntil).getTime()).toBe(
      NOW.getTime() + 15 * 60000,
    );
  });
});

describe('створення бронювання (BOOK-01, BOOK-07, BOOK-09)', () => {
  it('створює бронювання зі статусом booked і знімає hold', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const slot = firstAvailable(backend, date);
    const hold = backend.hold(slot);
    const booking = backend.createBooking({
      storeId: slot.storeId,
      rampId: slot.rampId,
      slotStart: slot.slotStart,
      holdToken: hold.holdToken,
      vehicleId: 'veh-seed-1',
      orderId: 'ORD-777',
      palletsCount: 10,
      confirmConflict: true,
    });

    expect(booking.status).toBe('booked');
    expect(booking.type).toBe('scheduled');
    expect(booking.vehicle.plateNumber).toBe('АА1234ВС');
    expect(booking.orderId).toBe('ORD-777');

    const grid = backend.slots(slot.storeId, date);
    const cell = grid.rows
      .flatMap((row) => row.cells)
      .find(
        (item) =>
          item.rampId === slot.rampId && item.slotStart === slot.slotStart,
      );
    expect(cell?.state).toBe('booked');
    expect(cell?.mine).toBe(true);
  });

  it('відхиляє слот, перехоплений іншим постачальником (409 SLOT_ALREADY_BOOKED)', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const slot = firstAvailable(backend, date);
    const hold = backend.hold(slot);
    // Гонка: інший постачальник встиг зайняти той самий ключ слота.
    jest.spyOn(backend, 'isForeignBooked').mockReturnValue(true);

    expect(
      problemCode(() =>
        backend.createBooking({
          storeId: slot.storeId,
          rampId: slot.rampId,
          slotStart: slot.slotStart,
          holdToken: hold.holdToken,
          vehicleId: 'veh-seed-1',
          palletsCount: 5,
        }),
      ),
    ).toBe(ERROR_CODES.slotAlreadyBooked);
  });

  it('відхиляє авто, важче за максимальну масу філії (422 VEHICLE_TOO_HEAVY)', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    let slot = firstAvailable(backend, date);
    // Потрібна філія з обмеженням, меншим за 40 т.
    if (slot.maxVehicleWeightTons >= 40) {
      slot = firstAvailable(backend, addDays(TODAY, 2));
    }
    const heavy = backend.createVehicle({
      plateNumber: 'ВВ0001СС',
      weightTons: slot.maxVehicleWeightTons + 10,
    });
    const hold = backend.hold(slot);

    expect(
      problemCode(() =>
        backend.createBooking({
          storeId: slot.storeId,
          rampId: slot.rampId,
          slotStart: slot.slotStart,
          holdToken: hold.holdToken,
          vehicleId: heavy.id,
          palletsCount: 5,
        }),
      ),
    ).toBe(ERROR_CODES.vehicleTooHeavy);
  });

  it('відхиляє палети поза діапазоном 1..33 (422 PALLETS_OUT_OF_RANGE)', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    expect(
      problemCode(() =>
        backend.createBooking({
          storeId: slot.storeId,
          rampId: slot.rampId,
          slotStart: slot.slotStart,
          holdToken: hold.holdToken,
          vehicleId: 'veh-seed-1',
          palletsCount: 34,
        }),
      ),
    ).toBe(ERROR_CODES.palletsOutOfRange);
  });

  it('вимагає чинну hold — інакше 409 HOLD_EXPIRED', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    expect(
      problemCode(() =>
        backend.createBooking({
          storeId: slot.storeId,
          rampId: slot.rampId,
          slotStart: slot.slotStart,
          holdToken: 'hold-невідомий',
          vehicleId: 'veh-seed-1',
          palletsCount: 5,
        }),
      ),
    ).toBe(ERROR_CODES.holdExpired);
  });

  it('дотримується ліміту активних майбутніх бронювань (422 BOOKING_LIMIT_EXCEEDED)', () => {
    const backend = backendAt({ maxActiveBookingsPerSupplier: 2 });
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    expect(backend.activeFutureBookings().length).toBeGreaterThan(2);
    expect(
      problemCode(() =>
        backend.createBooking({
          storeId: slot.storeId,
          rampId: slot.rampId,
          slotStart: slot.slotStart,
          holdToken: hold.holdToken,
          vehicleId: 'veh-seed-1',
          palletsCount: 5,
        }),
      ),
    ).toBe(ERROR_CODES.bookingLimitExceeded);
  });

  it('попереджає про перетин по тому самому авто і пропускає з confirmConflict', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const first = firstAvailable(backend, date);
    const hold1 = backend.hold(first);
    const created = backend.createBooking({
      storeId: first.storeId,
      rampId: first.rampId,
      slotStart: first.slotStart,
      holdToken: hold1.holdToken,
      vehicleId: 'veh-seed-1',
      palletsCount: 5,
      confirmConflict: true,
    });

    // Той самий час на іншій рампі/філії — попередження, але не блокування.
    const grid = backend.slots(first.storeId, date);
    const twin = grid.rows
      .flatMap((row) => row.cells)
      .find(
        (cell) =>
          cell.slotStart === created.slotStart &&
          cell.rampId !== created.rampId &&
          cell.state === 'available',
      );
    if (!twin) {
      return;
    }
    const hold2 = backend.hold({
      storeId: first.storeId,
      rampId: twin.rampId,
      slotStart: twin.slotStart,
    });
    const request = {
      storeId: first.storeId,
      rampId: twin.rampId,
      slotStart: twin.slotStart,
      holdToken: hold2.holdToken,
      vehicleId: 'veh-seed-1',
      palletsCount: 5,
    };
    expect(problemCode(() => backend.createBooking(request))).toBe(
      ERROR_CODES.vehicleTimeConflict,
    );

    // Попередження не знімає hold — клієнт повторює запит із confirmConflict.
    const second = backend.createBooking({ ...request, confirmConflict: true });
    expect(second.status).toBe('booked');
  });
});

describe('скасування та перенесення (SUP-RS-03, SUP-RS-04)', () => {
  it('скасування миттєво повертає слот у available', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const slot = firstAvailable(backend, date);
    const hold = backend.hold(slot);
    const booking = backend.createBooking({
      storeId: slot.storeId,
      rampId: slot.rampId,
      slotStart: slot.slotStart,
      holdToken: hold.holdToken,
      vehicleId: 'veh-seed-1',
      palletsCount: 5,
      confirmConflict: true,
    });

    const cancelled = backend.cancelBooking(booking.id, 'Змінився графік');
    expect(cancelled.status).toBe('cancelled');
    expect(cancelled.cancelReason).toBe('Змінився графік');

    const cell = backend
      .slots(slot.storeId, date)
      .rows.flatMap((row) => row.cells)
      .find(
        (item) =>
          item.rampId === slot.rampId && item.slotStart === slot.slotStart,
      );
    expect(cell?.state).toBe('available');
  });

  it('перенесення створює нове бронювання і скасовує старе', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const source = backend.upcoming(10)[0];
    const target = firstAvailable(backend, date);
    const hold = backend.hold(target);

    const moved = backend.createBooking({
      storeId: target.storeId,
      rampId: target.rampId,
      slotStart: target.slotStart,
      holdToken: hold.holdToken,
      vehicleId: source.vehicle.vehicleId,
      palletsCount: source.palletsCount,
      confirmConflict: true,
      transferFromBookingId: source.id,
    });

    expect(moved.id).not.toBe(source.id);
    expect(moved.palletsCount).toBe(source.palletsCount);
    expect(moved.driverId).toBe(source.driverId);
    expect(backend.upcoming(50).some((b) => b.id === source.id)).toBe(false);
  });
});

describe('заміна авто в бронюванні (SUP-RS-07)', () => {
  it('оновлює знімок авто у бронюванні', () => {
    const backend = backendAt();
    const booking = backend
      .upcoming(50)
      .find((item) => item.vehicle.vehicleId !== 'veh-seed-3');
    const store = backend.branch(booking!.storeId);
    if (store.maxVehicleWeightTons < 5) {
      return;
    }
    const updated = backend.changeBookingVehicle(booking!.id, 'veh-seed-3');
    expect(updated.vehicle.plateNumber).toBe('КА4321ТТ');
    expect(updated.vehicle.weightTons).toBe(5);
  });

  it('повторно перевіряє тоннаж і відхиляє надважке авто', () => {
    const backend = backendAt();
    const booking = backend.upcoming(50)[0];
    const store = backend.branch(booking.storeId);
    const heavy = backend.createVehicle({
      plateNumber: 'СС7777АА',
      weightTons: store.maxVehicleWeightTons + 5,
    });
    expect(
      problemCode(() => backend.changeBookingVehicle(booking.id, heavy.id)),
    ).toBe(ERROR_CODES.vehicleTooHeavy);
  });
});

describe('маршрутні листи (SUP-RS-01, SUP-RS-05)', () => {
  it('групує бронювання за датами і рахує точки', () => {
    const backend = backendAt();
    const sheets = backend.routeSheets();
    expect(sheets.length).toBeGreaterThan(0);
    const today = sheets.find((sheet) => sheet.date === TODAY);
    expect(today).toBeDefined();
    expect(today?.pointsCount).toBe(backend.routeSheet(TODAY).points.length);
    expect(today?.archived).toBe(false);
  });

  it('призначення водія на лист оновлює всі точки у статусі booked', () => {
    const backend = backendAt();
    const date = backend.routeSheets()[0].date;
    const sheet = backend.assignDriverToSheet(date, 'drv-seed-2');
    expect(sheet.driverId).toBe('drv-seed-2');
    expect(
      sheet.points
        .filter((point) => point.status === 'booked')
        .every((point) => point.driverId === 'drv-seed-2'),
    ).toBe(true);
  });

  it('деактивація водія знімає його з майбутніх листів (SUP-DRV-05)', () => {
    const backend = backendAt();
    const before = backend
      .upcoming(50)
      .filter((booking) => booking.driverId === 'drv-seed-1');
    expect(before.length).toBeGreaterThan(0);

    backend.setDriverActive('drv-seed-1', false);
    const after = backend
      .upcoming(50)
      .filter((booking) => booking.driverId === 'drv-seed-1');
    expect(after).toHaveLength(0);
    expect(
      backend.listDrivers().find((d) => d.id === 'drv-seed-1')?.active,
    ).toBe(false);
  });
});

describe('довідник машин (SUP-VEH-02, SUP-VEH-04)', () => {
  it('не дозволяє дублікат держномера в межах постачальника', () => {
    const backend = backendAt();
    expect(
      problemCode(() =>
        backend.createVehicle({ plateNumber: 'аа 1234 вс', weightTons: 5 }),
      ),
    ).toBe(ERROR_CODES.duplicatePlate);
  });

  it('забороняє видалення авто з активними бронюваннями', () => {
    const backend = backendAt();
    const used = backend.upcoming(10)[0].vehicle.vehicleId;
    expect(problemCode(() => backend.removeVehicle(used!))).toBe(
      ERROR_CODES.vehicleInUse,
    );
  });

  it('дозволяє деактивацію замість видалення', () => {
    const backend = backendAt();
    const used = backend.upcoming(10)[0].vehicle.vehicleId as string;
    expect(backend.setVehicleActive(used, false).active).toBe(false);
  });
});

describe('водії (SUP-DRV-02, SUP-DRV-03)', () => {
  it('створює водія з одноразовим паролем і нормалізованим телефоном', () => {
    const backend = backendAt();
    const created = backend.createDriver({
      phone: '050 777 88 99',
      firstName: 'Андрій',
      lastName: 'Шевчук',
      vehicleId: 'veh-seed-3',
    });
    expect(created.driver.phone).toBe('+380507778899');
    expect(created.driver.plateNumber).toBe('КА4321ТТ');
    expect(created.password).toHaveLength(8);
    expect(created.smsSent).toBe(true);
  });

  it('відхиляє дубль телефону (409 DRIVER_PHONE_TAKEN)', () => {
    const backend = backendAt();
    expect(
      problemCode(() =>
        backend.createDriver({
          phone: '+380671112233',
          firstName: 'Тест',
          lastName: 'Тестенко',
        }),
      ),
    ).toBe(ERROR_CODES.driverPhoneTaken);
  });
});

describe('автентифікація постачальника (SUP-AUTH-02, 03, 05)', () => {
  it('пускає з демо-обліковими даними', () => {
    const backend = backendAt();
    const session = backend.login(
      environment.demoLogin.email,
      environment.demoLogin.password,
    );
    expect(session.user.role).toBe('supplier_admin');
    expect(session.accessToken).toContain('mock-access');
  });

  it('блокує логін після 5 невдалих спроб поспіль', () => {
    const backend = backendAt();
    for (let i = 0; i < 4; i++) {
      expect(
        problemCode(() => backend.login(environment.demoLogin.email, 'ні')),
      ).toBe(ERROR_CODES.invalidCredentials);
    }
    expect(
      problemCode(() => backend.login(environment.demoLogin.email, 'ні')),
    ).toBe(ERROR_CODES.tooManyAttempts);
    // Навіть правильний пароль не проходить під час блокування.
    expect(
      problemCode(() =>
        backend.login(
          environment.demoLogin.email,
          environment.demoLogin.password,
        ),
      ),
    ).toBe(ERROR_CODES.tooManyAttempts);
  });

  it('відмовляє водієві з посиланням на застосунок водія', () => {
    const backend = backendAt();
    expect(problemCode(() => backend.login('+380671112233', 'pass'))).toBe(
      ERROR_CODES.driverAccount,
    );
  });
});
