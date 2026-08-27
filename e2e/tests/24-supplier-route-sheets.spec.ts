/**
 * S-08 Маршрутні листи: список, деталі, перенесення, скасування,
 * призначення і зняття водія, друк. S-11 — ліміт бронювань.
 *
 * Бронювання для перевірок створюються через API (сам процес бронювання
 * перевіряє 22-supplier-booking) і прибираються після прогону.
 */
import { expect, test } from '@playwright/test';
import {
  bodyText,
  cellAt,
  goto,
  kyivToday,
  languageProblems,
  loginSupplier,
  normalizedText,
  selectGridDate,
  shiftDate,
  stableSet,
  stamp,
  Sup,
  toast,
  uitestOrderId,
  uitestPhone,
  uitestPlate,
  workingDay,
  type DriverDto,
  type SlotDto,
  type StoreDto,
} from '../support/supplier';

let api: Sup;
let kharkiv: StoreDto[];
let driver: DriverDto;
const createdBookings: string[] = [];
const createdVehicles: string[] = [];
const createdDrivers: string[] = [];

test.beforeAll(async () => {
  api = await Sup.open();
  kharkiv = (await api.stores('Харків')).items;
  const phone = await api.freePhone();
  driver = await api.createDriver({
    phone,
    firstName: 'Лист',
    lastName: `UITEST-${phone.slice(-4)}`,
  });
  createdDrivers.push(driver.id);
});

test.afterAll(async () => {
  for (const id of createdBookings) {
    await api.cancelBooking(id).catch(() => undefined);
  }
  for (const id of createdVehicles) {
    await api.releaseVehicle(id).catch(() => undefined);
  }
  for (const id of createdDrivers) {
    await api.deactivateDriver(id).catch(() => undefined);
  }
  await api.dispose();
});

/** Бронювання на вказану дату у філії Харкова — основа для перевірок листа. */
async function seedBooking(options: {
  date: string;
  plate?: string;
  orderId?: string;
  pallets?: number;
  storeIndex?: number;
}): Promise<{ bookingId: string; slot: SlotDto; store: StoreDto; plate: string; orderId: string }> {
  const store = kharkiv[options.storeIndex ?? 0];
  const grid = await api.slots(store.storeId, options.date);
  const free = grid.slots.filter((s) => s.selectable).pop();
  expect(free, `у філії ${store.externalId} на ${options.date} мають бути вільні слоти`).toBeTruthy();

  const plate = options.plate ?? (await api.freePlate());
  const mark = plate.slice(2, 6);
  const orderId = options.orderId ?? uitestOrderId(mark);
  const booking = await api.createBooking({
    storeId: store.storeId,
    rampId: free!.rampId,
    slotStart: free!.slotStart,
    plateNumber: plate,
    weightTons: 3,
    palletsCount: options.pallets ?? 8,
    orderId,
  });
  createdBookings.push(booking.id);
  return { bookingId: booking.id, slot: free!, store, plate, orderId };
}

test.describe('S-08 Маршрутні листи', () => {
  test('S-08.1 список листів збігається з даними API', async ({ page }) => {
    const today = kyivToday();
    const dates = Array.from({ length: 22 }, (_, i) => shiftDate(today, i - 7));

    const read = async () => {
      const out: { date: string; points: number }[] = [];
      for (const date of dates) {
        const sheet = await api.sheet(date).catch(() => null);
        if (sheet && sheet.points.length > 0) {
          out.push({ date, points: sheet.points.length });
        }
      }
      return out;
    };

    const before = await read();
    await loginSupplier(page);
    await goto(page, '/route-sheets');
    await expect(page.locator('.sheets li').first()).toBeVisible();
    const upcomingHrefs = await page.locator('.sheets a').evaluateAll((els) =>
      els.map((e) => e.getAttribute('href') ?? ''),
    );
    await page.locator('button:has-text("Архів")').click();
    await page.waitForTimeout(400);
    const archiveHrefs = await page.locator('.sheets a').evaluateAll((els) =>
      els.map((e) => e.getAttribute('href') ?? ''),
    );
    const after = await read();

    const { must } = stableSet(before, after, (s) => s.date);
    const shown = [...upcomingHrefs, ...archiveHrefs].map((h) => h.replace('/route-sheets/', ''));

    for (const sheet of must) {
      expect(shown, `лист на ${sheet.date} має бути у списку`).toContain(sheet.date);
      const tab = sheet.date >= today ? upcomingHrefs : archiveHrefs;
      expect(
        tab.map((h) => h.replace('/route-sheets/', '')),
        `лист на ${sheet.date} має бути у вкладці ${sheet.date >= today ? 'Актуальні' : 'Архів'}`,
      ).toContain(sheet.date);
    }
  });

  test('S-08.2 деталі листа показують усі точки з правильними полями', async ({ page }) => {
    const date = workingDay(3);
    const seeded = await seedBooking({ date });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    await expect(page.locator('.table tbody tr').first()).toBeVisible();

    const sheet = await api.sheet(date);
    expect(await page.locator('.table tbody tr').count(), `в API ${sheet.points.length} точок`).toBe(
      sheet.points.length,
    );

    const text = await bodyText(page);
    for (const point of sheet.points) {
      expect(text, `час точки ${point.localTime}`).toContain(point.localTime);
      expect(text, `адреса ${point.address}`).toContain(point.address);
      expect(text, `магазин ${point.storeName}`).toContain(point.storeName);
      if (point.orderId) {
        expect(text, `замовлення ${point.orderId}`).toContain(point.orderId);
      }
    }

    const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
    await expect(myRow).toBeVisible();
    await expect(myRow, 'статус нового бронювання').toContainText('Заброньовано');
    expect(normalizedText(await myRow.innerText()), 'кількість палет').toContain('8');
  });

  test('S-08.3 у колонці «Авто» видно держномер саме цього бронювання', async ({ page }) => {
    const date = workingDay(3);
    // Держномер, якого свідомо немає в довіднику: у листі має стояти саме він.
    const plate = await api.freePlate();
    const seeded = await seedBooking({ date, plate });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
    await expect(myRow).toBeVisible();

    const shown = normalizedText(await myRow.innerText());
    const selected = await myRow
      .locator('select')
      .first()
      .evaluate((el) => (el as HTMLSelectElement).selectedOptions[0]?.text ?? '')
      .catch(() => '');

    expect(
      shown.includes(plate) || selected.includes(plate),
      `у бронюванні авто ${plate}, а в рядку листа показано «${selected || shown}». ` +
        'Користувач має бачити авто свого бронювання, а не чуже.',
    ).toBe(true);
  });

  test('S-08.4 призначення водія на весь лист', async ({ page }) => {
    const date = workingDay(4);
    const seeded = await seedBooking({ date });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    await expect(page.locator('#sheet-driver')).toBeVisible();

    const label = `${driver.lastName} ${driver.firstName} · ${driver.phone}`;
    const options = await page.locator('#sheet-driver option').allInnerTexts();
    expect(options.map(normalizedText), 'усі активні водії мають бути у переліку').toContain(label);

    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/route-sheets/driver') && r.request().method() === 'POST'),
      page.locator('#sheet-driver').selectOption({ label }),
    ]);
    await expect(toast(page, 'Водія призначено')).toBeVisible();

    const sheet = await api.sheet(date);
    const point = sheet.points.find((p) => p.bookingId === seeded.bookingId);
    expect(point?.driverId, 'водій має бути записаний у бронювання').toBe(driver.id);
    await expect(page.locator('.table tbody tr').filter({ hasText: seeded.orderId })).toContainText(
      driver.lastName,
    );

    await expect(
      page.locator('.sheet-driver'),
      'кабінет має пояснювати, що зняти водія з усього листа не можна',
    ).toContainText('Зняти водія з усього листа не можна');
  });

  test('S-08.5 призначення і зняття водія в окремій точці', async ({ page }) => {
    const date = workingDay(4);
    const seeded = await seedBooking({ date });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
    await expect(myRow).toBeVisible();

    const driverSelect = myRow.locator('select').last();
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/route-sheets/driver')),
      driverSelect.selectOption({ label: `${driver.lastName} ${driver.firstName}` }),
    ]);
    await page.waitForTimeout(800);
    expect(
      (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId,
      'водія призначено на точку',
    ).toBe(driver.id);

    // Зняття водія з точки — порожній варіант у списку.
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/route-sheets/driver')),
      page.locator('.table tbody tr').filter({ hasText: seeded.orderId }).locator('select').last().selectOption(''),
    ]);
    await page.waitForTimeout(800);
    expect(
      (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId,
      'водія знято з точки',
    ).toBeNull();
  });

  test('S-08.6 перенесення бронювання на інший слот', async ({ page }) => {
    test.setTimeout(150_000);
    const date = workingDay(3);
    // Авто з довідника: перенесення підставляє його у форму за держномером.
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3, brand: 'UITEST перенесення' });
    createdVehicles.push(vehicle.id);
    const seeded = await seedBooking({ date, storeIndex: 2, plate });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
    await expect(myRow).toBeVisible();
    await myRow.locator('button:has-text("Перенести")').click();

    await page.waitForURL(new RegExp(`/booking/stores/${seeded.store.storeId}`), { timeout: 20_000 });
    await expect(page.locator('.transfer-banner'), 'має бути банер перенесення').toContainText(
      'Перенесення бронювання',
    );

    await selectGridDate(page, date);
    const grid = await api.slots(seeded.store.storeId, date);
    const target = grid.slots
      .filter((s) => s.selectable && s.slotStart !== seeded.slot.slotStart)
      .pop();
    expect(target, 'потрібен інший вільний слот').toBeTruthy();

    const column = seeded.store.ramps.findIndex((r) => r.rampId === target!.rampId);
    const cell = cellAt(page, `${target!.localStart}`, column);
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/slots/hold') && r.request().method() === 'POST'),
      cell.locator('button.slot').click(),
    ]);

    // Значення полів — це input.value, а не текст панелі.
    await expect(page.locator('.panel__foot .btn--primary')).toHaveText(/Перенести бронювання/);
    await expect(page.locator('#order-id'), 'номер замовлення переноситься').toHaveValue(seeded.orderId);
    await expect(page.locator('#pallets'), 'палети переносяться').toHaveValue('8');
    await expect(page.locator('.panel .vehicle--active'), 'авто бронювання підставлено').toContainText(plate);
    await expect(page.locator('.panel__transfer'), 'панель нагадує, що це перенесення').toContainText(
      'Перенесення бронювання',
    );

    const [moved] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/bookings/') && r.request().method() === 'PATCH'),
      page.locator('.panel__foot .btn--primary').click(),
    ]);
    expect([200, 201], 'перенесення має пройти').toContain(moved.status());
    await expect(toast(page, 'Бронювання перенесено')).toBeVisible();

    // Бекенд оформлює перенесення як нове бронювання замість старого.
    const movedBooking = await moved.json();
    createdBookings.push(movedBooking.id);

    const sheet = await api.sheet(date);
    const point = sheet.points.find((p) => p.bookingId === movedBooking.id);
    expect(point, 'перенесене бронювання має бути у листі').toBeTruthy();
    expect(point!.localTime, 'у листі має бути новий час').toBe(target!.localStart);
    expect(point!.orderId, 'номер замовлення не має загубитись').toBe(seeded.orderId);
    expect(
      sheet.points.filter((p) => p.orderId === seeded.orderId).length,
      'старої точки в листі лишатись не має',
    ).toBe(1);

    const fresh = await api.slots(seeded.store.storeId, date);
    const oldSlot = fresh.slots.find(
      (s) => s.slotStart === seeded.slot.slotStart && s.rampId === seeded.slot.rampId,
    );
    expect(oldSlot?.state, 'старий слот має звільнитись').toBe('available');
  });

  test('S-08.7 скасування з причиною звільняє слот', async ({ page }) => {
    const date = workingDay(3);
    const seeded = await seedBooking({ date, storeIndex: 1 });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
    await expect(myRow).toBeVisible();
    await myRow.locator('button:has-text("Скасувати")').click();

    await expect(page.locator('.modal__title')).toHaveText('Скасувати бронювання?');
    await page.locator('#cancel-reason').fill('UITEST: перевірка скасування');
    const [cancelled] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/bookings/') && r.request().method() === 'DELETE'),
      page.locator('.modal__window button:has-text("Скасувати")').click(),
    ]);
    expect(cancelled.status()).toBe(200);

    await expect(toast(page, 'Бронювання скасовано')).toBeVisible();
    await expect(page.locator('.table tbody tr').filter({ hasText: seeded.orderId })).toHaveCount(0);

    const fresh = await api.slots(seeded.store.storeId, date);
    const slot = fresh.slots.find((s) => s.slotStart === seeded.slot.slotStart && s.rampId === seeded.slot.rampId);
    expect(slot?.state, 'слот скасованого бронювання має звільнитись').toBe('available');
  });

  test('S-08.8 друкована форма містить усі потрібні поля', async ({ page }) => {
    const date = workingDay(4);
    const seeded = await seedBooking({ date });

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    await Promise.all([
      // Перехід усередині SPA не змінює стан завантаження сторінки,
      // тому чекаємо саме на дані листа.
      page.waitForResponse((r) => r.url().includes('/route-sheets?') && r.url().includes(date), { timeout: 20_000 }),
      page.locator('a:has-text("Роздрукувати")').click(),
    ]);
    await page.waitForURL(new RegExp(`/route-sheets/${date}/print`), { timeout: 20_000 });
    await expect(page.locator('body')).not.toContainText('Завантаження…');

    const text = await bodyText(page);
    const sheet = await api.sheet(date);

    // Заголовки таблиці набрані капітеллю через CSS — порівнюємо без регістру.
    const lower = text.toLocaleLowerCase('uk-UA');
    expect(text, 'заголовок').toContain('Маршрутний лист');
    expect(text, 'постачальник').toContain(sheet.supplierName ?? 'ТОВ');
    expect(text, 'номер версії листа').toMatch(/Версія листа № \d+/);
    expect(text, 'місце для водія').toContain('Водій');
    expect(text, 'місце для телефону').toContain('Телефон');
    expect(lower, 'підпис представника магазину').toContain('підпис представника магазину');
    for (const column of ['час', 'магазин', 'адреса', 'авто', 'замовлення', 'палети']) {
      expect(lower, `колонка «${column}»`).toContain(column);
    }

    for (const point of sheet.points) {
      expect(text, `час ${point.localTime}`).toContain(point.localTime);
      expect(text, `адреса ${point.address}`).toContain(point.address);
      expect(text, `авто ${point.plateNumber}`).toContain(point.plateNumber);
      expect(text, `палети ${point.palletsCount}`).toContain(String(point.palletsCount));
      if (point.orderId) {
        expect(text, `замовлення ${point.orderId}`).toContain(point.orderId);
      }
    }
    expect(text, 'моє бронювання має бути у друкованій формі').toContain(seeded.plate);
  });

  test('S-08.10 вибір «Водія не призначено» у листі не показує неправду', async ({ page }) => {
    const date = workingDay(5);
    const seeded = await seedBooking({ date });
    await api.assignSheetDriver(date, driver.id);

    await loginSupplier(page);
    await goto(page, `/route-sheets/${date}`);
    const select = page.locator('#sheet-driver');
    await expect(select).toBeVisible();
    await expect(select.locator('option:checked')).toContainText(driver.lastName);

    // Кабінет свідомо не вміє знімати водія з усього листа, але порожній
    // варіант у списку лишається доступним для вибору.
    let requested = false;
    page.on('request', (r) => {
      if (r.url().includes('/route-sheets/driver')) {
        requested = true;
      }
    });
    await select.selectOption('');
    await page.waitForTimeout(1200);

    const stillAssigned =
      (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId === driver.id;
    const showsNoDriver = normalizedText(await select.locator('option:checked').innerText()).includes(
      'Водія не призначено',
    );

    expect(
      requested || !showsNoDriver || !stillAssigned,
      'у списку показано «Водія не призначено», хоча водій листа не змінився: ' +
        'керування має або виконувати дію, або не пропонувати недоступний варіант',
    ).toBe(true);
  });

  test('S-08.9 X-07 маршрутні листи українською, без ключів перекладу', async ({ page }) => {
    const date = workingDay(4);
    await loginSupplier(page);
    for (const path of ['/route-sheets', `/route-sheets/${date}`, `/route-sheets/${date}/print`]) {
      await goto(page, path);
      await page.waitForTimeout(500);
      const problems = languageProblems(await bodyText(page));
      expect(problems, `${path}: ${problems.join(', ')}`).toHaveLength(0);
    }
  });
});

test.describe('S-11 Ліміт бронювань', () => {
  test('S-11 повідомлення про ліміт активних бронювань', async () => {
    const limit = 50;
    test.skip(
      true,
      `ліміт активних майбутніх бронювань постачальника — ${limit} (StorePolicy.maxActiveBookingsPerSupplier). ` +
        'Щоб побачити повідомлення в інтерфейсі, треба створити майже 50 бронювань на спільному стенді — ' +
        'це заблокувало б слоти для решти перевірок і для демо. Перевірку слід виконувати на окремому ' +
        'стенді або після зниження ліміту в конфігурації магазину адміністратором.',
    );
  });
});
