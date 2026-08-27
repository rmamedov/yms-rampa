/**
 * S-06 Бронювання слота (панель, валідація, таймер холду) і S-07 конкуренція.
 *
 * Бронюємо тільки на завтра й далі та тільки у філіях Харкова — щоб не
 * заважати іншим перевіркам стенду. Слот під клік вибираємо з кінця списку
 * вільних: паралельні перевірки зазвичай беруть найперші.
 */
import { Locator, Page, expect, test } from '@playwright/test';
import { registerArtifact } from '../support/env';
import {
  bodyText,
  cellAt,
  goto,
  loginSupplier,
  normalizedText,
  selectGridDate,
  stamp,
  Sup,
  uitestOrderId,
  uitestPlate,
  workingDay,
  type SlotDto,
  type StoreDto,
} from '../support/supplier';

let api: Sup;
let kharkiv: StoreDto[];
const createdBookings: string[] = [];
const createdVehicles: string[] = [];

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
  await api.dispose();
});

interface Held {
  store: StoreDto;
  date: string;
  slot: SlotDto;
  column: number;
  cell: Locator;
}

/** Відкриває сітку і бере hold на вільний слот; при гонці пробує наступний. */
async function holdFreeSlot(page: Page, store: StoreDto, date: string): Promise<Held> {
  await goto(page, `/booking/stores/${store.storeId}`);
  await selectGridDate(page, date);

  const grid = await api.slots(store.storeId, date);
  const candidates = grid.slots.filter((s) => s.selectable).reverse();
  expect(candidates.length, `у філії ${store.externalId} на ${date} мають бути вільні слоти`).toBeGreaterThan(0);

  for (const slot of candidates.slice(0, 8)) {
    const column = store.ramps.findIndex((r) => r.rampId === slot.rampId);
    const cell = cellAt(page, `${slot.localStart}`, column);
    const button = cell.locator('button.slot');
    if ((await button.count()) === 0) {
      continue;
    }

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/slots/hold') && r.request().method() === 'POST', {
        timeout: 20_000,
      }),
      button.click(),
    ]);
    if (response.status() === 201) {
      await page.waitForSelector('.panel', { timeout: 15_000 });
      return { store, date, slot, column, cell };
    }
    // Слот перехопили паралельні перевірки — беремо наступний.
    await page.waitForTimeout(500);
  }
  throw new Error('не вдалося взяти hold на жоден вільний слот');
}

async function submitDisabled(page: Page): Promise<boolean> {
  return page.locator('.panel__foot .btn--primary').isDisabled();
}

async function panelErrors(page: Page): Promise<string[]> {
  return (await page.locator('.panel .field__error').allInnerTexts()).map(normalizedText);
}

/** Повідомлення саме під вказаним полем, а не будь-де в панелі. */
async function fieldError(page: Page, inputSelector: string): Promise<string> {
  const errors = page.locator('.field', { has: page.locator(inputSelector) }).locator('.field__error');
  return (await errors.count()) === 0 ? '' : normalizedText(await errors.first().innerText());
}

test.describe('S-06 Бронювання', () => {
  test('S-06.1 клік по вільному слоту відкриває панель із деталями слота і таймером', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(1);
    const held = await holdFreeSlot(page, store, date);

    const panel = normalizedText(await page.locator('.panel').innerText());
    expect(panel, 'у шапці панелі має бути час слота').toContain(held.slot.localStart);
    expect(panel, 'і назва рампи').toContain(store.ramps[held.column].name);
    await expect(page.locator('.panel__timer')).toContainText(/Час на оформлення: 0[45]:\d{2}/);

    // Поки слот тримає ця вкладка, у сітці він «Оформлюється» для решти.
    const fresh = await api.slots(store.storeId, date);
    const sameSlot = fresh.slots.find(
      (s) => s.slotStart === held.slot.slotStart && s.rampId === held.slot.rampId,
    );
    expect(sameSlot?.state, 'API має показувати слот як held').toBe('held');

    await page.locator('.panel__close').click();
  });

  test('S-06.2 палети: 0, 34 і порожнє поле відхиляються, 1 і 33 приймаються', async ({ page }) => {
    // Авто заводимо заздалегідь — панель читає довідник при відкритті.
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3 });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    await holdFreeSlot(page, store, workingDay(1));
    await page.locator('.panel .vehicle', { hasText: plate }).click();

    for (const [value, expectError] of [
      ['0', true],
      ['1', false],
      ['33', false],
      ['34', true],
      ['', true],
    ] as const) {
      await page.locator('#pallets').fill(value);
      await page.waitForTimeout(200);
      const errors = await panelErrors(page);
      if (expectError && value !== '') {
        expect(errors.join(' | '), `палети «${value}» мають бути відхилені`).toContain('Вкажіть від 1 до 33 палет');
      }
      if (!expectError) {
        expect(errors, `палети «${value}» мають прийматись без помилок`).toHaveLength(0);
      }
      expect(await submitDisabled(page), `кнопка бронювання при палетах «${value}»`).toBe(expectError);
    }

    await page.locator('.panel__close').click();
  });

  test('S-06.3 держномер: довжина, символи і нормалізація регістру', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    await holdFreeSlot(page, store, workingDay(1));

    await page.locator('.panel button:has-text("Додати нове авто")').click();
    const plate = page.locator('#new-plate');

    await plate.fill('AB1');
    await page.waitForTimeout(200);
    expect(await fieldError(page, '#new-plate'), 'три символи — замало').toContain(
      'Держномер має містити від 4 до 12 символів',
    );

    await plate.fill('ABCDEFGHIJKLM');
    await page.waitForTimeout(200);
    expect(await fieldError(page, '#new-plate'), 'тринадцять символів — забагато').toContain(
      'Держномер має містити від 4 до 12 символів',
    );

    await plate.fill('AB#123');
    await page.waitForTimeout(200);
    expect(await fieldError(page, '#new-plate'), 'спецсимволи заборонені').toContain(
      'Держномер може містити лише літери та цифри',
    );

    await plate.fill('  aa 12 34 bc  ');
    await page.waitForTimeout(200);
    expect(await fieldError(page, '#new-plate'), 'пробіли і нижній регістр — валідне значення').toBe('');

    await page.locator('.panel__close').click();
  });

  test('S-06.4 тоннаж понад ліміт філії відхиляється з назвою ліміту', async ({ page }) => {
    await loginSupplier(page);
    // Найлегша філія Харкова — на ній ліміт видно найкраще.
    const store = [...kharkiv].sort((a, b) => a.maxVehicleWeightTons - b.maxVehicleWeightTons)[0];
    await holdFreeSlot(page, store, workingDay(1));

    await page.locator('.panel button:has-text("Додати нове авто")').click();
    await page.locator('#new-plate').fill(await api.freePlate());

    await page.locator('#new-weight').fill(String(store.maxVehicleWeightTons + 2.5));
    await page.waitForTimeout(250);
    expect((await panelErrors(page)).join(' | '), 'ліміт філії має бути названий числом').toContain(
      `Авто перевищує максимальну масу для цієї філії — ${store.maxVehicleWeightTons} т`,
    );
    expect(await submitDisabled(page), 'із заважким авто бронювати не можна').toBe(true);

    await page.locator('#new-weight').fill(String(store.maxVehicleWeightTons));
    await page.waitForTimeout(250);
    expect(await panelErrors(page), 'рівно ліміт — допустимо').toHaveLength(0);

    await page.locator('#new-weight').fill('0');
    await page.waitForTimeout(250);
    expect((await panelErrors(page)).join(' | '), 'нульова вантажопідйомність').toMatch(
      /більшою за 0|Вкажіть вантажопідйомність/,
    );

    await page.locator('.panel__close').click();
  });

  test('S-06.5 номер замовлення понад 64 символи не має мовчки обрізатись', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv[0];
    await holdFreeSlot(page, store, workingDay(1));

    const long = `UITEST-${'X'.repeat(70)}`;
    await page.locator('#order-id').fill(long);
    await page.waitForTimeout(250);

    const value = await page.locator('#order-id').inputValue();
    const errors = (await panelErrors(page)).join(' | ');
    expect(
      value === long || /64/.test(errors),
      `введено ${long.length} символів, у полі лишилось ${value.length}, повідомлення: «${errors}». ` +
        'Користувач має або бачити свій текст цілком, або отримати відмову — мовчазне обрізання псує дані.',
    ).toBe(true);

    // Рівно 64 символи — допустима межа.
    const exact = `UITEST-${'Y'.repeat(57)}`;
    await page.locator('#order-id').fill(exact);
    await page.waitForTimeout(200);
    expect(await page.locator('#order-id').inputValue()).toHaveLength(64);
    expect(await panelErrors(page)).toHaveLength(0);

    await page.locator('.panel__close').click();
  });

  test('S-06.6 без авто і без палет бронювання недоступне', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv[0];
    await holdFreeSlot(page, store, workingDay(1));

    expect(await submitDisabled(page), 'порожня форма — кнопка вимкнена').toBe(true);

    await page.locator('#pallets').fill('10');
    await page.waitForTimeout(200);
    expect(await submitDisabled(page), 'палети є, авто немає — все ще вимкнена').toBe(true);

    await page.locator('.panel button:has-text("Додати нове авто")').click();
    await page.locator('#new-plate').fill(await api.freePlate());
    await page.waitForTimeout(200);
    expect(await submitDisabled(page), 'авто без вантажопідйомності не рахується').toBe(true);

    await page.locator('#new-weight').fill('3');
    await page.waitForTimeout(250);
    expect(await submitDisabled(page), 'усі поля заповнені — можна бронювати').toBe(false);

    await page.locator('.panel__close').click();
  });

  test('S-06.7 бронювання з новим авто: слот стає зайнятим, дані збережені', async ({ page }) => {
    test.setTimeout(120_000);
    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(1);
    const held = await holdFreeSlot(page, store, date);

    const plate = await api.freePlate();
    const mark = plate.slice(2, 6);
    const orderId = uitestOrderId(mark);

    await page.locator('.panel button:has-text("Додати нове авто")').click();
    await page.locator('#new-plate').fill(plate.toLowerCase());
    await page.locator('#new-brand').fill('UITEST Volvo FH');
    await page.locator('#new-weight').fill('5');
    await page.locator('#order-id').fill(orderId);
    await page.locator('#pallets').fill('12');
    await page.waitForTimeout(300);

    const [created] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/bookings') && r.request().method() === 'POST', {
        timeout: 30_000,
      }),
      page.locator('.panel__foot .btn--primary').click(),
    ]);
    expect(created.status(), 'бронювання має створитись').toBe(201);
    const booking = await created.json();
    createdBookings.push(booking.id);
    registerArtifact('booking', booking.id, `${orderId} ${held.slot.slotStart}`);

    await expect(page.locator('.toast__text').first()).toHaveText('Слот заброньовано');
    await page.waitForURL(new RegExp(`/route-sheets/${date}`), { timeout: 20_000 });

    // Дані бронювання в API — саме те, що ввів користувач.
    const sheet = await api.sheet(date);
    const point = sheet.points.find((p) => p.bookingId === booking.id);
    expect(point, 'бронювання має зʼявитись у маршрутному листі').toBeTruthy();
    expect(point!.orderId).toBe(orderId);
    expect(point!.palletsCount).toBe(12);
    expect(point!.plateNumber, 'держномер має зберегтись у верхньому регістрі').toBe(plate);
    expect(point!.localTime).toBe(held.slot.localStart);

    // Авто потрапило у довідник постачальника.
    const vehicle = (await api.vehicles()).find((v) => v.plateNumber === plate);
    expect(vehicle, 'нове авто має зʼявитись у довіднику').toBeTruthy();
    createdVehicles.push(vehicle!.id);

    // Слот у сітці став зайнятим і неклікабельним.
    await goto(page, `/booking/stores/${store.storeId}`);
    await selectGridDate(page, date);
    const cell = cellAt(page, `${held.slot.localStart}`, held.column);
    await expect(cell.locator('.slot')).toHaveText('Зайнято');
    expect(await cell.locator('button.slot').count(), 'зайнятий слот не має бути кнопкою').toBe(0);
  });

  test('S-06.8 авто з довідника: пошук за держномером і бронювання', async ({ page }) => {
    test.setTimeout(120_000);
    const plate = await api.freePlate();
    const mark = plate.slice(2, 6);
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand: 'UITEST Scania' });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(2);
    const held = await holdFreeSlot(page, store, date);

    const apiActive = (await api.vehicles()).filter((v) => v.active).length;
    await expect
      .poll(() => page.locator('.panel .vehicle').count(), {
        message: `у панелі має бути весь довідник активних авто (${apiActive})`,
        timeout: 10_000,
      })
      .toBe(apiActive);

    await page.locator('.panel input[type=search]').fill(plate.slice(2, 6));
    await page.waitForTimeout(300);
    await expect(page.locator('.panel .vehicle')).toHaveCount(1);
    await page.locator('.panel .vehicle').click();

    const orderId = uitestOrderId(mark);
    await page.locator('#order-id').fill(orderId);
    await page.locator('#pallets').fill('1');
    await page.waitForTimeout(200);

    const [created] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/bookings') && r.request().method() === 'POST'),
      page.locator('.panel__foot .btn--primary').click(),
    ]);
    expect(created.status()).toBe(201);
    const booking = await created.json();
    createdBookings.push(booking.id);
    registerArtifact('booking', booking.id, `${orderId} ${held.slot.slotStart}`);
    expect(booking.vehicle.plateNumber).toBe(plate);
    expect(booking.palletsCount).toBe(1);
  });

  test('S-06.9 дублікат держномера в панелі не дає створити друге авто', async ({ page }) => {
    const plate = await api.freePlate();
    const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
    createdVehicles.push(vehicle.id);

    await loginSupplier(page);
    const store = kharkiv[0];
    await holdFreeSlot(page, store, workingDay(1));

    await page.locator('.panel button:has-text("Додати нове авто")').click();
    await page.locator('#new-plate').fill(plate);
    await page.locator('#new-weight').fill('4');
    await page.waitForTimeout(300);

    expect((await panelErrors(page)).join(' | '), 'дублікат має бути названий').toContain(
      'Авто з таким номером уже є у вашому довіднику',
    );
    expect(await submitDisabled(page), 'з дублікатом бронювати не можна').toBe(true);

    await page.locator('.panel__close').click();
  });

  test('S-06.10 закриття панелі одразу звільняє слот', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(1);
    const held = await holdFreeSlot(page, store, date);

    const [released] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/slots/hold') && r.request().method() === 'DELETE', {
        timeout: 15_000,
      }),
      page.locator('.panel__close').click(),
    ]);
    expect(released.status(), 'закриття панелі має знімати hold').toBe(204);

    await page.waitForTimeout(800);
    const grid = await api.slots(store.storeId, date);
    const slot = grid.slots.find((s) => s.slotStart === held.slot.slotStart && s.rampId === held.slot.rampId);
    expect(slot?.state, 'слот має знову стати вільним').toBe('available');
  });

  test('S-06.11 таймер холду: тікає, продовжується heartbeat-ом і зрештою вичерпується', async ({ page }) => {
    test.setTimeout(120_000);
    await page.clock.install({ time: new Date() });
    await loginSupplier(page);

    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const held = await holdFreeSlot(page, store, workingDay(1));

    const timer = page.locator('.panel__timer');
    const seconds = async () => {
      const [, mm, ss] = /(\d{2}):(\d{2})/.exec(normalizedText(await timer.innerText())) ?? [];
      return Number(mm) * 60 + Number(ss);
    };

    const started = await seconds();
    expect(started, 'холд живе 5 хвилин — таймер має стартувати близько 05:00').toBeGreaterThan(280);
    expect(started).toBeLessThanOrEqual(300);

    await page.clock.fastForward('00:20');
    await page.waitForTimeout(300);
    const after20 = await seconds();
    expect(started - after20, 'за 20 секунд таймер має зменшитись приблизно на 20').toBeGreaterThanOrEqual(18);

    // HOLD-02: активність продовжує холд — heartbeat раз на хвилину.
    let extended = false;
    page.on('request', (r) => {
      if (r.url().includes('/slots/hold') && r.method() === 'PATCH') {
        extended = true;
      }
    });
    await page.clock.fastForward('01:00');
    await page.waitForTimeout(1500);
    expect(extended, 'кабінет має продовжувати холд, поки користувач заповнює форму').toBe(true);
    await expect(timer, 'після продовження часу знову близько 5 хвилин').toContainText(
      /Час на оформлення: 0[45]:\d{2}/,
    );

    // Якщо продовжити не вдалося (мережа/TTL), панель має чесно про це сказати.
    await page.route('**/slots/hold', (route) =>
      route.request().method() === 'PATCH' ? route.abort() : route.continue(),
    );
    await page.clock.fastForward('05:30');
    await page.waitForTimeout(1000);

    await expect(timer).toHaveText('Час оформлення вичерпано, оновіть сітку');
    expect(await submitDisabled(page), 'після вичерпання бронювати не можна').toBe(true);
    await expect(page.locator('#pallets')).toBeDisabled();
    await expect(page.locator('.toast__text').first()).toContainText('Час оформлення вичерпано');

    expect(held.slot.slotStart).toBeTruthy();
    await page.unroute('**/slots/hold');
  });

  test('S-07 конкуренція: другий користувач не може взяти зайнятий слот', async ({ page, browser }) => {
    test.setTimeout(150_000);
    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(2);
    const held = await holdFreeSlot(page, store, date);

    const second = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'uk-UA', timezoneId: 'Europe/Kyiv' });
    const other = await second.newPage();
    try {
      await loginSupplier(other);
      await goto(other, `/booking/stores/${store.storeId}`);
      await selectGridDate(other, date);

      const cell = other
        .locator('.slot-grid tbody tr')
        .filter({ has: other.locator(`th:text-is("${held.slot.localStart}")`) })
        .locator('.slot-grid__cell')
        .nth(held.column);

      // Другий бачить слот як «Оформлюється» і не може його взяти.
      await expect(cell.locator('.slot'), 'слот під холдом має бути підписаний').toHaveText('Оформлюється');
      expect(await cell.locator('button.slot').count(), 'слот під холдом не має бути кнопкою').toBe(0);

      // Перший доводить бронювання до кінця.
      const plate = await api.freePlate();
      const mark = plate.slice(2, 6);
      await page.locator('.panel button:has-text("Додати нове авто")').click();
      await page.locator('#new-plate').fill(plate);
      await page.locator('#new-weight').fill('3');
      await page.locator('#order-id').fill(uitestOrderId(mark));
      await page.locator('#pallets').fill('3');
      await page.waitForTimeout(300);
      const [created] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/bookings') && r.request().method() === 'POST'),
        page.locator('.panel__foot .btn--primary').click(),
      ]);
      expect(created.status(), 'перший має забронювати успішно').toBe(201);
      const booking = await created.json();
      createdBookings.push(booking.id);
      registerArtifact('booking', booking.id, `конкуренція ${held.slot.slotStart}`);
      const vehicle = (await api.vehicles()).find((v) => v.plateNumber === plate);
      if (vehicle) {
        createdVehicles.push(vehicle.id);
      }

      // Другий після оновлення бачить «Зайнято».
      await other.locator('button:has-text("Оновити")').click();
      await other.waitForTimeout(1200);
      await expect(cell.locator('.slot')).toHaveText('Зайнято');
      expect(await bodyText(other)).toContain('Зайнято');
    } finally {
      await second.close();
    }
  });
});
