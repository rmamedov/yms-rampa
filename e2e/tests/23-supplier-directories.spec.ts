/**
 * S-09 Довідник авто і S-10 Водії.
 *
 * Очікування знову беруться з API: скільки авто і водіїв у постачальника,
 * як бекенд нормалізував держномер і телефон.
 */
import { Page, expect, test } from '@playwright/test';
import { registerArtifact } from '../support/env';
import {
  bodyText,
  goto,
  languageProblems,
  loginSupplier,
  normalizedText,
  Sup,
  toast,
  workingDay,
  type StoreDto,
} from '../support/supplier';

let api: Sup;
let kharkiv: StoreDto[];
const createdVehicles: string[] = [];
const createdDrivers: string[] = [];
const createdBookings: string[] = [];

test.beforeAll(async () => {
  api = await Sup.open();
  kharkiv = (await api.stores('Харків')).items;
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

function row(page: Page, text: string) {
  return page.locator('.table tbody tr').filter({ hasText: text });
}

async function saveVehicleForm(page: Page, fields: { plate?: string; brand?: string; weight?: string }) {
  if (fields.plate !== undefined) {
    await page.locator('#veh-plate').fill(fields.plate);
  }
  if (fields.brand !== undefined) {
    await page.locator('#veh-brand').fill(fields.brand);
  }
  if (fields.weight !== undefined) {
    await page.locator('#veh-weight').fill(fields.weight);
  }
  await page.waitForTimeout(200);
}

test.describe('S-09 Довідник авто', () => {
  test('S-09.1 X-01 у таблиці всі авто з API з правильними полями', async ({ page }) => {
    const plate = await api.freePlate();
    const created = await api.createVehicle({ plateNumber: plate, weightTons: 6.5, brand: 'UITEST MAN TGX' });
    createdVehicles.push(created.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');

    const vehicles = await api.vehicles();
    const rows = page.locator('.table tbody tr');
    await expect(rows.first()).toBeVisible();
    expect(await rows.count(), `в API ${vehicles.length} авто`).toBe(vehicles.length);

    const text = await bodyText(page);
    for (const vehicle of vehicles) {
      expect(text, `авто ${vehicle.plateNumber} має бути в таблиці`).toContain(vehicle.plateNumber);
    }

    const line = normalizedText(await row(page, plate).innerText());
    expect(line, 'марка').toContain('UITEST MAN TGX');
    expect(line, 'вантажопідйомність').toContain('6.5 т');
    expect(line, 'статус').toContain('Активне');
  });

  test('S-09.2 створення авто нормалізує держномер', async ({ page }) => {
    const plate = await api.freePlate();

    await loginSupplier(page);
    await goto(page, '/vehicles');
    await page.locator('button:has-text("Додати авто")').click();
    await expect(page.locator('.modal__title')).toHaveText('Нове авто');

    // Нижній регістр і пробіли — користувач так і вводить.
    await saveVehicleForm(page, { plate: plate.toLowerCase().replace(/(.{2})(.{4})/, '$1 $2 '), brand: 'UITEST DAF', weight: '12' });
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/vehicles') && r.request().method() === 'POST'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);
    expect(response.status(), 'авто має створитись').toBe(201);
    const vehicle = await response.json();
    createdVehicles.push(vehicle.id);
    registerArtifact('vehicle', vehicle.id, vehicle.plateNumber);

    await expect(page.locator('.toast__text').first()).toHaveText('Авто збережено');
    expect(vehicle.plateNumber, 'номер має зберегтись у верхньому регістрі без пробілів').toBe(plate);
    await expect(row(page, plate)).toBeVisible();
  });

  test('S-09.3 валідація форми авто', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/vehicles');
    await page.locator('button:has-text("Додати авто")').click();

    const save = page.locator('.modal__window button:has-text("Зберегти")');
    await expect(save, 'порожня форма не зберігається').toBeDisabled();
    await expect(page.locator('.modal__window')).toContainText('Вкажіть держномер');

    await saveVehicleForm(page, { plate: 'AB1', weight: '5' });
    await expect(page.locator('.modal__window')).toContainText('Держномер має містити від 4 до 12 символів');
    await expect(save).toBeDisabled();

    await saveVehicleForm(page, { plate: await api.freePlate(), weight: '' });
    await expect(page.locator('.modal__window')).toContainText('Вкажіть вантажопідйомність');
    await expect(save).toBeDisabled();

    await saveVehicleForm(page, { weight: '0' });
    await expect(page.locator('.modal__window')).toContainText('Вантажопідйомність має бути більшою за 0');
    await expect(save).toBeDisabled();

    await saveVehicleForm(page, { weight: '3' });
    await expect(save, 'коректні дані — можна зберігати').toBeEnabled();
  });

  test('S-09.4 дублікат держномера в межах постачальника відхиляється', async ({ page }) => {
    const plate = await api.freePlate();
    const existing = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
    createdVehicles.push(existing.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');
    await page.locator('button:has-text("Додати авто")').click();
    await saveVehicleForm(page, { plate, weight: '4' });

    await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
    await expect(page.locator('.modal__window button:has-text("Зберегти")')).toBeDisabled();

    // Той самий номер у нижньому регістрі — теж дублікат.
    await saveVehicleForm(page, { plate: plate.toLowerCase() });
    await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  });

  test('S-09.5 редагування зберігає нові марку і вантажопідйомність', async ({ page }) => {
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand: 'UITEST старий' });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');
    await row(page, plate).locator('button:has-text("Редагувати")').click();
    await expect(page.locator('.modal__title')).toHaveText('Редагування авто');
    expect(await page.locator('#veh-plate').inputValue(), 'форма має відкритись із поточними даними').toBe(plate);

    await saveVehicleForm(page, { brand: 'UITEST новий', weight: '18.5' });
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'PATCH'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);

    await expect(row(page, plate)).toContainText('UITEST новий');
    const updated = (await api.vehicles()).find((v) => v.id === vehicle.id);
    expect(updated?.brand).toBe('UITEST новий');
    expect(updated?.weightTons).toBe(18.5);
  });

  test('S-09.6 деактивація і повернення авто в роботу', async ({ page }) => {
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');

    await row(page, plate).locator('button:has-text("Деактивувати")').click();
    await expect(page.locator('.toast__text').first()).toHaveText('Авто деактивовано');
    await expect(row(page, plate)).toContainText('Деактивоване');
    expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(false);

    await row(page, plate).locator('button:has-text("Активувати")').click();
    await page.waitForTimeout(800);
    await expect(row(page, plate)).toContainText('Активне');
    expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(true);
  });

  test('S-09.7 видалення авто, яке ніде не використовується', async ({ page }) => {
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');
    await row(page, plate).locator('button:has-text("Видалити")').click();
    await expect(page.locator('.modal__window')).toContainText(`Видалити авто ${plate}?`);

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'DELETE'),
      page.locator('.modal__window button:has-text("Видалити")').click(),
    ]);

    expect(
      response.status(),
      'авто без жодного бронювання має видалятись; ' +
        `сервер відповів ${response.status()}: ${normalizedText(await response.text()).slice(0, 200)}`,
    ).toBe(204);
    await expect(row(page, plate)).toHaveCount(0);
    expect((await api.vehicles()).some((v) => v.id === vehicle.id)).toBe(false);
  });

  test('S-09.8 авто з активним бронюванням видалити не можна, деактивувати — можна', async ({ page }) => {
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3 });
    createdVehicles.push(vehicle.id);

    const store = kharkiv[0];
    const date = workingDay(1);
    const grid = await api.slots(store.storeId, date);
    const free = grid.slots.filter((s) => s.selectable).pop();
    expect(free, 'потрібен вільний слот').toBeTruthy();
    const booking = await api.createBooking({
      storeId: store.storeId,
      rampId: free!.rampId,
      slotStart: free!.slotStart,
      plateNumber: plate,
      weightTons: 3,
      palletsCount: 4,
      orderId: 'UITEST-veh-lock',
    });
    createdBookings.push(booking.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');
    await row(page, plate).locator('button:has-text("Видалити")').click();
    await page.locator('.modal__window button:has-text("Видалити")').click();

    await expect(page.locator('.toast__text').first()).toContainText(
      'Авто привʼязане до активних бронювань — доступна лише деактивація',
    );
    await expect(row(page, plate), 'авто має лишитись у довіднику').toBeVisible();

    await row(page, plate).locator('button:has-text("Деактивувати")').click();
    await expect(row(page, plate)).toContainText('Деактивоване');
  });

  test('S-09.9 пошук у довіднику за номером і за маркою', async ({ page }) => {
    const plate = await api.freePlate();
    const mark = plate.slice(2, 6);
    const brand = `UITEST Renault ${mark}`;
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');
    const search = page.locator('.vehicles__search');
    const rows = page.locator('.table tbody tr');

    const vehicles = await api.vehicles();
    await search.fill(mark);
    await page.waitForTimeout(300);
    const expected = vehicles.filter((v) => v.plateNumber.includes(mark) || (v.brand ?? '').includes(mark)).length;
    expect(await rows.count(), `пошук за фрагментом номера «${mark}»`).toBe(expected);

    // Держномер із пробілами — так його диктують і записують.
    await search.fill(`${plate.slice(0, 2)} ${plate.slice(2, 6)} ${plate.slice(6)}`);
    await page.waitForTimeout(300);
    await expect(rows, 'пошук за номером із пробілами').toHaveCount(1);

    await search.fill('renault');
    await page.waitForTimeout(300);
    const byBrandWord = vehicles.filter((v) => (v.brand ?? '').toLowerCase().includes('renault')).length;
    expect(await rows.count(), 'пошук за одним словом марки').toBe(byBrandWord);

    await search.fill('немаєтакогономера');
    await page.waitForTimeout(300);
    await expect(page.locator('.empty-state')).toBeVisible();
  });

  test('S-09.10 пошук за маркою з кількох слів знаходить авто', async ({ page }) => {
    const plate = await api.freePlate();
    const mark = plate.slice(2, 6);
    const brand = `Renault ${mark}`;
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    await goto(page, '/vehicles');
    const rows = page.locator('.table tbody tr');

    await page.locator('.vehicles__search').fill(brand);
    await page.waitForTimeout(400);
    await expect(
      rows,
      `поле обіцяє «Пошук за держномером або маркою», марка авто — «${brand}»; ` +
        'пошук за нею має знаходити авто',
    ).toHaveCount(1);
  });
});

test.describe('S-10 Водії', () => {
  test('S-10.1 X-01 у таблиці всі водії з API', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/drivers');

    const drivers = await api.drivers();
    const rows = page.locator('.table tbody tr');
    await expect(rows.first()).toBeVisible();
    expect(await rows.count(), `в API ${drivers.length} водіїв`).toBe(drivers.length);

    const text = await bodyText(page);
    for (const driver of drivers) {
      expect(text, `водій ${driver.lastName} має бути у списку`).toContain(driver.lastName);
      expect(text, `телефон ${driver.phone} має бути показаний`).toContain(driver.phone);
    }
  });

  test('S-10.2 телефон приймається у всіх поширених форматах', async ({ page }) => {
    test.setTimeout(180_000);
    await loginSupplier(page);

    const variants = [
      { label: '0XXXXXXXXX', value: (d: string) => `099000${d}` },
      { label: '+380XXXXXXXXX', value: (d: string) => `+38099000${d}` },
      { label: '380XXXXXXXXX', value: (d: string) => `38099000${d}` },
      { label: 'з пробілами і дефісами', value: (d: string) => `0 (99) 000-${d.slice(0, 2)}-${d.slice(2)}` },
    ];
    // Кожному формату — свій вільний номер: діапазон +38099000XXXX спільний
    // з попередніми прогонами, і зайнятий номер дав би 409 замість перевірки.
    const phones = [
      await api.freePhone(),
      await api.freePhone(),
      await api.freePhone(),
      await api.freePhone(),
    ];

    for (const [index, variant] of variants.entries()) {
      const expectedPhone = phones[index];
      const d = expectedPhone.slice(-4);
      await goto(page, '/drivers');
      await page.locator('button:has-text("Додати водія")').click();

      await page.locator('#drv-phone').fill(variant.value(d));
      await page.locator('#drv-last').fill(`UITEST-${d}`);
      await page.locator('#drv-first').fill('Формат');
      await page.waitForTimeout(200);
      await expect(
        page.locator('.modal__window button:has-text("Зберегти")'),
        `формат «${variant.label}» має прийматись`,
      ).toBeEnabled();

      const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
        page.locator('.modal__window button:has-text("Зберегти")').click(),
      ]);
      expect(response.status(), `формат «${variant.label}»: створення водія`).toBe(201);
      const created = await response.json();
      createdDrivers.push(created.driverId ?? created.id);
      registerArtifact('driver', created.driverId ?? created.id, expectedPhone);

      await page.locator('.modal__window button:has-text("Закрити")').click();
      await expect(
        row(page, expectedPhone),
        `телефон «${variant.value(d)}» має зберегтись як ${expectedPhone}`,
      ).toBeVisible();
    }
  });

  test('S-10.3 дублікат телефону відхиляється зрозумілим повідомленням', async ({ page }) => {
    await loginSupplier(page);
    const existing = (await api.drivers()).find((d) => d.phone.startsWith('+38099000')) ?? (await api.drivers())[0];

    await goto(page, '/drivers');
    await page.locator('button:has-text("Додати водія")').click();
    await page.locator('#drv-phone').fill(existing.phone);
    await page.locator('#drv-last').fill('UITEST-дубль');
    await page.locator('#drv-first').fill('Тест');
    await page.waitForTimeout(200);

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);
    expect(response.status(), 'дублікат телефону має відхилятись сервером').toBe(409);
    await expect(page.locator('.toast__text').first()).toContainText(
      'Водій з таким телефоном уже зареєстрований',
    );
  });

  test('S-10.4 валідація телефону та ПІБ', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/drivers');
    await page.locator('button:has-text("Додати водія")').click();

    const save = page.locator('.modal__window button:has-text("Зберегти")');
    await expect(save, 'без ПІБ зберегти не можна').toBeDisabled();
    await expect(page.locator('.modal__window')).toContainText('Вкажіть прізвище');

    await page.locator('#drv-phone').fill('+38099');
    await page.waitForTimeout(200);
    await expect(page.locator('.modal__window')).toContainText('Формат телефону: +380XXXXXXXXX');
    await expect(save).toBeDisabled();

    await page.locator('#drv-phone').fill('+380990001234567');
    await page.waitForTimeout(200);
    await expect(page.locator('.modal__window'), 'задовгий номер теж відхиляється').toContainText(
      'Формат телефону: +380XXXXXXXXX',
    );

    await page.locator('#drv-phone').fill(await api.freePhone());
    await page.locator('#drv-last').fill('UITEST');
    await page.waitForTimeout(200);
    await expect(page.locator('.modal__window'), 'імʼя лишається обовʼязковим').toContainText('Вкажіть імʼя');
    await expect(save).toBeDisabled();
  });

  test('S-10.5 пароль показується один раз і зникає після закриття', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/drivers');

    const phone = await api.freePhone();
    const mark = phone.slice(-4);
    await page.locator('button:has-text("Додати водія")').click();
    await page.locator('#drv-phone').fill(phone);
    await page.locator('#drv-last').fill(`UITEST-${mark}`);
    await page.locator('#drv-first').fill('Пароль');
    await page.waitForTimeout(200);

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);
    const created = await response.json();
    createdDrivers.push(created.driverId ?? created.id);
    registerArtifact('driver', created.driverId ?? created.id, phone);

    const passwordBox = page.locator('.drivers__password');
    await expect(passwordBox).toBeVisible();
    const password = normalizedText(await passwordBox.innerText());
    expect(password.length, 'пароль має бути непорожнім').toBeGreaterThan(6);
    await expect(page.locator('.modal__window')).toContainText('Запишіть пароль — повторно він не показується');
    await expect(page.locator('.modal__window'), 'логін водія — його телефон').toContainText(phone);

    await page.locator('.modal__window button:has-text("Закрити")').click();
    await expect(page.locator('.drivers__password')).toHaveCount(0);
    expect(await bodyText(page), 'після закриття пароля на екрані бути не має').not.toContain(password);

    await page.reload();
    await page.waitForLoadState('networkidle');
    expect(await bodyText(page), 'і після перезавантаження теж').not.toContain(password);
  });

  test('S-10.6 перегенерація пароля видає новий пароль', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/drivers');

    const phone = await api.freePhone();
    const mark = phone.slice(-4);
    await page.locator('button:has-text("Додати водія")').click();
    await page.locator('#drv-phone').fill(phone);
    await page.locator('#drv-last').fill(`UITEST-${mark}`);
    await page.locator('#drv-first').fill('Регенерація');
    await page.waitForTimeout(200);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);
    const created = await response.json();
    createdDrivers.push(created.driverId ?? created.id);
    const first = normalizedText(await page.locator('.drivers__password').innerText());
    await page.locator('.modal__window button:has-text("Закрити")').click();

    await row(page, phone).locator('button:has-text("Перегенерувати пароль")').click();
    await expect(page.locator('.modal__window')).toContainText(`UITEST-${mark}`);
    await page.locator('.modal__window button:has-text("Так")').click();

    await expect(page.locator('.drivers__password')).toBeVisible();
    const second = normalizedText(await page.locator('.drivers__password').innerText());
    expect(second, 'новий пароль має відрізнятись від попереднього').not.toBe(first);
    await expect(page.locator('.toast__text').first()).toHaveText('Пароль перегенеровано');
  });

  test('S-10.7 деактивація прибирає водія з призначень', async ({ page }) => {
    await loginSupplier(page);

    const phone = await api.freePhone();
    const mark = phone.slice(-4);
    await goto(page, '/drivers');
    await page.locator('button:has-text("Додати водія")').click();
    await page.locator('#drv-phone').fill(phone);
    await page.locator('#drv-last').fill(`UITEST-${mark}`);
    await page.locator('#drv-first').fill('Деактивація');
    await page.waitForTimeout(200);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);
    const created = await response.json();
    const driverId = created.driverId ?? created.id;
    createdDrivers.push(driverId);
    await page.locator('.modal__window button:has-text("Закрити")').click();

    await row(page, phone).locator('button:has-text("Деактивувати")').click();
    await expect(page.locator('.modal__window')).toContainText('Деактивувати водія');
    await page.locator('.modal__window button:has-text("Так")').click();

    // Попередній тост «Водія створено» ще міг не згаснути (живе 6 с).
    await expect(toast(page, 'Водія деактивовано')).toBeVisible();
    await expect(row(page, phone)).toContainText('Деактивований');
    expect((await api.drivers()).find((d) => d.id === driverId)?.active).toBe(false);

    // Деактивований водій не має пропонуватись у маршрутному листі.
    await goto(page, `/route-sheets/${workingDay(1)}`);
    await page.waitForTimeout(800);
    const options = await page.locator('#sheet-driver option').allInnerTexts();
    expect(options.join(' | '), 'деактивованого водія в переліку бути не має').not.toContain(`UITEST-${mark}`);
  });

  test('S-10.8 X-01 дропдаун авто при створенні водія показує весь довідник', async ({ page }) => {
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 9 });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    await goto(page, '/drivers');
    await page.locator('button:has-text("Додати водія")').click();

    const active = (await api.vehicles()).filter((v) => v.active);
    const options = page.locator('#drv-vehicle option');
    await expect
      .poll(() => options.count(), { message: 'у списку мають бути всі активні авто плюс «—»' })
      .toBe(active.length + 1);

    const text = (await options.allInnerTexts()).join(' | ');
    for (const item of active) {
      expect(text, `авто ${item.plateNumber} має бути у списку`).toContain(item.plateNumber);
    }

    // Обране авто зберігається у водія.
    const phone = await api.freePhone();
    const mark = phone.slice(-4);
    await page.locator('#drv-vehicle').selectOption({ label: `${plate} · 9 т` });
    await page.locator('#drv-phone').fill(phone);
    await page.locator('#drv-last').fill(`UITEST-${mark}`);
    await page.locator('#drv-first').fill('Авто');
    await page.waitForTimeout(200);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
      page.locator('.modal__window button:has-text("Зберегти")').click(),
    ]);
    const created = await response.json();
    createdDrivers.push(created.driverId ?? created.id);
    registerArtifact('driver', created.driverId ?? created.id, phone);
    await page.locator('.modal__window button:has-text("Закрити")').click();

    await expect(row(page, phone), 'у рядку водія має бути його авто').toContainText(plate);
  });

  test('S-10.9 X-07 довідники українською, без ключів перекладу', async ({ page }) => {
    await loginSupplier(page);
    for (const path of ['/vehicles', '/drivers']) {
      await goto(page, path);
      await page.waitForTimeout(400);
      const problems = languageProblems(await bodyText(page));
      expect(problems, `${path}: ${problems.join(', ')}`).toHaveLength(0);
    }
  });
});
