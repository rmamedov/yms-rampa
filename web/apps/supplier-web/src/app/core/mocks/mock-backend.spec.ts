import { MockBackend } from './mock-backend';
import { ERROR_CODES, ApiProblemError } from '../api/problem';
import { addDays, kyivDateIso } from '../util/kyiv-time';
import { environment } from '../../../environments/environment';
import type { NetworkSettings, SlotKey, VehicleInput } from '../models/models';

const NOW = new Date('2026-03-12T07:00:00Z');
const TODAY = kyivDateIso(NOW);

const SEED_VEHICLE: VehicleInput = {
  plateNumber: 'АА1234ВС',
  weightTons: 3.5,
  brand: 'Renault Master',
};

function backendAt(settings: Partial<NetworkSettings> = {}): MockBackend {
  return new MockBackend(() => NOW, settings);
}

interface Candidate extends SlotKey {
  maxVehicleWeightTons: number;
}

/** Перший вільний слот у першій київській філії з вільними слотами. */
function firstAvailable(backend: MockBackend, date = TODAY): Candidate {
  for (const branch of backend.branches('Київ')) {
    const grid = backend.slots(branch.storeId, date);
    const slot = grid.slots.find((item) => item.selectable);
    if (slot) {
      return {
        storeId: branch.storeId,
        rampId: slot.rampId,
        slotStart: slot.slotStart,
        maxVehicleWeightTons: grid.maxVehicleWeightTons,
      };
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

/** Точки маршрутних листів на кілька найближчих днів — джерело «найближчих поставок». */
function upcomingPoints(backend: MockBackend, days = 7) {
  return Array.from({ length: days }, (_, i) => addDays(TODAY, i)).flatMap(
    (date) => backend.routeSheet(date).points,
  );
}

describe('довідник міст і філій (SUP-CITY-01, SUP-BR-01)', () => {
  it('показує лише активні та видимі постачальнику філії', () => {
    const backend = backendAt();
    const cities = backend.cities();
    expect(cities.length).toBeGreaterThan(10);
    expect(cities.every((city) => city.storeCount > 0)).toBe(true);

    const kyiv = cities.find((city) => city.city === 'Київ');
    expect(kyiv?.storeCount).toBe(backend.branches('Київ').length);
  });

  it('віддає філію в поданні supplierView з рампами і параметрами слотів', () => {
    const branch = backendAt().branches('Київ')[0];
    expect(branch.ramps.length).toBeGreaterThan(0);
    expect(branch.ramps[0]).toEqual(
      expect.objectContaining({ rampId: expect.any(String), number: 1 }),
    );
    expect(branch.slotSizeMinutes).toBeGreaterThan(0);
    expect(branch.bookingHorizonDays).toBeGreaterThan(0);
  });

  it('невидиму філію ховає під 404 STORE_NOT_FOUND, без розкриття причини', () => {
    const backend = backendAt();
    const hidden = backend
      .allStores()
      .find((store) => !store.visibleToSuppliers);
    expect(hidden).toBeDefined();
    expect(problemCode(() => backend.branch(hidden!.storeId))).toBe(
      ERROR_CODES.storeNotFound,
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

  it('кладе горизонт у розширення problem-документа', () => {
    const backend = backendAt();
    const store = backend.branches('Київ')[0];
    try {
      backend.slots(store.storeId, addDays(TODAY, 60));
    } catch (error) {
      expect((error as ApiProblemError).problem.meta).toEqual({
        horizonDays: expect.any(Number),
      });
    }
  });
});

describe('холди слота (HOLD-01, HOLD-02, HOLD-03)', () => {
  it('дозволяє лише одну активну hold на слот — другий отримує 409 SLOT_HELD', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    expect(hold.holdToken).toBeTruthy();
    expect(hold.secondsLeft).toBe(300);
    expect(problemCode(() => backend.hold(slot))).toBe(ERROR_CODES.slotHeld);
  });

  it('показує зайнятий холдом слот у сітці як held', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const slot = firstAvailable(backend, date);
    backend.hold(slot);
    const cell = backend
      .slots(slot.storeId, date)
      .slots.find(
        (item) =>
          item.rampId === slot.rampId && item.slotStart === slot.slotStart,
      );
    expect(cell?.state).toBe('held');
    expect(cell?.selectable).toBe(false);
  });

  it('звільняє слот після release з ключем слота і токеном', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    backend.releaseHold(slot, hold.holdToken);
    expect(backend.hold(slot).holdToken).toBeTruthy();
  });

  it('продовжує hold, але не далі за holdMaxMinutes', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    const hold = backend.hold(slot);
    const extended = backend.extendHold(slot, hold.holdToken);
    expect(new Date(extended.expiresAt).getTime()).toBe(
      NOW.getTime() + 5 * 60000,
    );
    expect(new Date(extended.maxExpiresAt).getTime()).toBe(
      NOW.getTime() + 15 * 60000,
    );
  });

  it('відхиляє продовження чужого холду (409 HOLD_NOT_OWNED)', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
    backend.hold(slot);
    expect(problemCode(() => backend.extendHold(slot, 'чужий-токен'))).toBe(
      ERROR_CODES.holdNotOwned,
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
      vehicle: SEED_VEHICLE,
      orderId: 'ORD-777',
      palletsCount: 10,
      confirmConflict: true,
    });

    expect(booking.status).toBe('booked');
    expect(booking.type).toBe('scheduled');
    expect(booking.vehicle.plateNumber).toBe('АА1234ВС');
    expect(booking.orderId).toBe('ORD-777');
    expect(booking.store.city).toBeTruthy();
    expect(booking.localDate).toBe(date);

    const cell = backend
      .slots(slot.storeId, date)
      .slots.find(
        (item) =>
          item.rampId === slot.rampId && item.slotStart === slot.slotStart,
      );
    expect(cell?.state).toBe('booked');
  });

  it('відхиляє слот, перехоплений іншим постачальником (409 SLOT_ALREADY_BOOKED)', () => {
    const backend = backendAt();
    const slot = firstAvailable(backend, addDays(TODAY, 1));
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
          vehicle: SEED_VEHICLE,
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
    const hold = backend.hold(slot);

    try {
      backend.createBooking({
        storeId: slot.storeId,
        rampId: slot.rampId,
        slotStart: slot.slotStart,
        holdToken: hold.holdToken,
        vehicle: {
          plateNumber: 'ВВ0001СС',
          weightTons: slot.maxVehicleWeightTons + 10,
        },
        palletsCount: 5,
      });
      throw new Error('Очікувалась помилка тоннажу');
    } catch (error) {
      const problem = (error as ApiProblemError).problem;
      expect(problem.code).toBe(ERROR_CODES.vehicleTooHeavy);
      expect(problem.meta?.['maxVehicleWeightTons']).toBe(
        slot.maxVehicleWeightTons,
      );
    }
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
          vehicle: SEED_VEHICLE,
          palletsCount: 34,
        }),
      ),
    ).toBe(ERROR_CODES.palletsOutOfRange);
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
          vehicle: SEED_VEHICLE,
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
      vehicle: SEED_VEHICLE,
      palletsCount: 5,
      confirmConflict: true,
    });

    // Той самий час на іншій рампі — попередження, але не блокування.
    const twin = backend
      .slots(first.storeId, date)
      .slots.find(
        (slot) =>
          slot.slotStart === created.slotStart &&
          slot.rampId !== created.rampId &&
          slot.selectable,
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
      vehicle: SEED_VEHICLE,
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
      vehicle: SEED_VEHICLE,
      palletsCount: 5,
      confirmConflict: true,
    });

    const cancelled = backend.cancelBooking(booking.id, 'Змінився графік');
    expect(cancelled.status).toBe('cancelled');

    const cell = backend
      .slots(slot.storeId, date)
      .slots.find(
        (item) =>
          item.rampId === slot.rampId && item.slotStart === slot.slotStart,
      );
    expect(cell?.state).toBe('available');
  });

  it('перенесення (PATCH зі слотом) створює нове бронювання і скасовує старе', () => {
    const backend = backendAt();
    const date = addDays(TODAY, 1);
    const source = backend.booking(upcomingPoints(backend)[0].bookingId);
    const target = firstAvailable(backend, date);
    const hold = backend.hold(target);

    const moved = backend.reschedule(source.id, {
      storeId: target.storeId,
      rampId: target.rampId,
      slotStart: target.slotStart,
      holdToken: hold.holdToken,
      vehicle: {
        plateNumber: source.vehicle.plateNumber,
        weightTons: source.vehicle.weightTons,
      },
      palletsCount: source.palletsCount,
      confirmConflict: true,
    });

    expect(moved.id).not.toBe(source.id);
    expect(moved.rescheduleOf).toBe(source.id);
    expect(moved.palletsCount).toBe(source.palletsCount);
    expect(moved.driverId).toBe(source.driverId);
    expect(backend.booking(source.id).status).toBe('cancelled');
  });
});

describe('заміна авто в бронюванні (SUP-RS-07, EDIT-05)', () => {
  it('оновлює знімок авто у бронюванні', () => {
    const backend = backendAt();
    const booking = backend
      .booking(upcomingPoints(backend)[0].bookingId);
    const store = backend.branch(booking.storeId);
    if (store.maxVehicleWeightTons < 5) {
      return;
    }
    const updated = backend.reassignBooking(booking.id, {
      vehicle: { plateNumber: 'КА4321ТТ', weightTons: 5 },
    });
    expect(updated.vehicle.plateNumber).toBe('КА4321ТТ');
    expect(updated.vehicle.weightTons).toBe(5);
  });

  it('повторно перевіряє тоннаж і відхиляє надважке авто', () => {
    const backend = backendAt();
    const booking = backend.booking(upcomingPoints(backend)[0].bookingId);
    const store = backend.branch(booking.storeId);
    expect(
      problemCode(() =>
        backend.reassignBooking(booking.id, {
          vehicle: {
            plateNumber: 'СС7777АА',
            weightTons: store.maxVehicleWeightTons + 5,
          },
        }),
      ),
    ).toBe(ERROR_CODES.vehicleTooHeavy);
  });
});

describe('маршрутні листи (SUP-RS-01, SUP-RS-05)', () => {
  it('віддає лист лише на конкретну дату і вимагає коректний формат', () => {
    const backend = backendAt();
    const sheet = backend.routeSheet(TODAY);
    expect(sheet.date).toBe(TODAY);
    expect(sheet.points.length).toBeGreaterThan(0);
    expect(sheet.points[0]).toEqual(
      expect.objectContaining({
        bookingId: expect.any(String),
        localTime: expect.any(String),
        plateNumber: expect.any(String),
      }),
    );
    expect(problemCode(() => backend.routeSheet('12.03.2026'))).toBe(
      ERROR_CODES.validationFailed,
    );
  });

  it('призначення водія на лист оновлює всі точки і повертає агрегат листа', () => {
    const backend = backendAt();
    const assignment = backend.assignDriverToSheet(TODAY, 'drv-seed-2');
    expect(assignment.routeSheetId).toBeTruthy();
    expect(assignment.entries.every((e) => e.driverId === 'drv-seed-2')).toBe(
      true,
    );
    expect(
      backend.routeSheet(TODAY).points.every((p) => p.driverId === 'drv-seed-2'),
    ).toBe(true);
  });

  it('порожній водій знімає водія з усього листа (ISSUE-18)', () => {
    const backend = backendAt();
    backend.assignDriverToSheet(TODAY, 'drv-seed-2');

    const assignment = backend.assignDriverToSheet(TODAY, null);

    expect(assignment.entries.every((e) => e.driverId === null)).toBe(true);
    expect(
      backend.routeSheet(TODAY).points.every((p) => p.driverId === null),
    ).toBe(true);
  });

  it('призначення водія на окрему точку перекриває призначення листа', () => {
    const backend = backendAt();
    backend.assignDriverToSheet(TODAY, 'drv-seed-2');
    const point = backend.routeSheet(TODAY).points[0];
    backend.assignDriverToBooking(point.bookingId, null);
    const updated = backend
      .routeSheet(TODAY)
      .points.find((p) => p.bookingId === point.bookingId);
    expect(updated?.driverId).toBeNull();
  });

  it('деактивація водія знімає його з майбутніх листів (SUP-DRV-05)', () => {
    const backend = backendAt();
    const before = upcomingPoints(backend).filter(
      (point) => point.driverId === 'drv-seed-1',
    );
    expect(before.length).toBeGreaterThan(0);

    backend.setDriverActive('drv-seed-1', false);
    const after = upcomingPoints(backend).filter(
      (point) => point.driverId === 'drv-seed-1',
    );
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
    ).toBe(ERROR_CODES.vehiclePlateDuplicate);
  });

  it('забороняє видалення авто з активними бронюваннями', () => {
    const backend = backendAt();
    const plate = upcomingPoints(backend)[0].plateNumber;
    const used = backend.listVehicles().find((v) => v.plateNumber === plate);
    expect(problemCode(() => backend.removeVehicle(used!.id))).toBe(
      ERROR_CODES.vehicleHasActiveBookings,
    );
  });

  it('дозволяє деактивацію замість видалення', () => {
    const backend = backendAt();
    const plate = upcomingPoints(backend)[0].plateNumber;
    const used = backend.listVehicles().find((v) => v.plateNumber === plate);
    expect(backend.setVehicleActive(used!.id, false).active).toBe(false);
  });

  it('за замовчуванням віддає і деактивовані авто, includeInactive=false — ні', () => {
    const backend = backendAt();
    expect(backend.listVehicles().some((v) => !v.active)).toBe(true);
    expect(backend.listVehicles(false).every((v) => v.active)).toBe(true);
  });
});

describe('водії (SUP-DRV-02, SUP-DRV-03)', () => {
  it('створює водія з одноразовим паролем і нормалізованим телефоном', () => {
    const backend = backendAt();
    const created = backend.createDriver({
      phone: '050 777 88 99',
      firstName: 'Андрій',
      lastName: 'Шевчук',
      defaultVehicleId: 'veh-seed-3',
    });
    expect(created.driver.phone).toBe('+380507778899');
    expect(created.driver.defaultVehicleId).toBe('veh-seed-3');
    expect(created.login).toBe('+380507778899');
    expect(created.password).toHaveLength(8);
    expect(created.passwordNotice).toContain('Запишіть пароль');
  });

  it('відхиляє дубль телефону (409 DRIVER_PHONE_DUPLICATE)', () => {
    const backend = backendAt();
    expect(
      problemCode(() =>
        backend.createDriver({
          phone: '+380671112233',
          firstName: 'Тест',
          lastName: 'Тестенко',
        }),
      ),
    ).toBe(ERROR_CODES.driverPhoneDuplicate);
  });
});

describe('автентифікація постачальника (SUP-AUTH-02, 03, 05)', () => {
  it('пускає з демо-обліковими даними і віддає профіль партнерського контуру', () => {
    const backend = backendAt();
    const session = backend.login(
      environment.demoLogin.email,
      environment.demoLogin.password,
    );
    expect(session.profile.role).toBe('supplier_admin');
    expect(session.profile.contour).toBe('partner');
    expect(session.tokenType).toBe('Bearer');
    expect(session.accessToken).toContain('mock-access');
  });

  it('блокує логін після 5 невдалих спроб поспіль (AUTH_ACCOUNT_LOCKED)', () => {
    const backend = backendAt();
    for (let i = 0; i < 4; i++) {
      expect(
        problemCode(() => backend.login(environment.demoLogin.email, 'ні')),
      ).toBe(ERROR_CODES.authInvalidCredentials);
    }
    expect(
      problemCode(() => backend.login(environment.demoLogin.email, 'ні')),
    ).toBe(ERROR_CODES.authAccountLocked);
    // Навіть правильний пароль не проходить під час блокування.
    expect(
      problemCode(() =>
        backend.login(
          environment.demoLogin.email,
          environment.demoLogin.password,
        ),
      ),
    ).toBe(ERROR_CODES.authAccountLocked);
  });

  it('водієві відповідає тим самим кодом, що й на невірний пароль (DRV-10)', () => {
    const backend = backendAt();
    expect(problemCode(() => backend.login('+380671112233', 'pass'))).toBe(
      ERROR_CODES.authInvalidCredentials,
    );
  });
});
