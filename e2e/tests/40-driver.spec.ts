/**
 * Застосунок водія (`driver.`) — сценарії D-01…D-06, D-09 плану UI-тестування.
 *
 * Правило те саме, що й у 01-data-completeness: еталон береться з API,
 * а не з голови автора. Кожна перевірка складу маршрутного листа звіряє
 * екран із відповіддю `GET /api/driver/v1/route-sheet`, а координати для
 * навігатора — з каталогом філій постачальника.
 *
 * Дані готуються через кабінет постачальника (support/driver-data.ts):
 * водій із маркованим телефоном +38099000XXXX і бронювання `UITEST-<мітка>`.
 */
import { APIRequestContext, expect, test } from '@playwright/test';
import { HOSTS, api, hasHorizontalScroll, pageText, untranslatedFragments } from '../support/env';
import {
  CatalogStore,
  TestBooking,
  TestDriver,
  addDays,
  arrivalSandbox,
  captureExternalOpens,
  createBooking,
  createDriver,
  driverAuth,
  driverLoginUi,
  driverRouteSheet,
  kyivDateKey,
  kyivStores,
  mark,
  openRouteSheet,
  openedUrls,
  pointCard,
  releaseBookings,
  releaseDrivers,
  storeSeesStatus,
  storeStaffAuth,
  storeStartUnloading,
  supplierAuth,
} from '../support/driver-data';

/** Спільні дані для перевірок, які нічого не змінюють. */
interface Shared {
  ctx: APIRequestContext;
  supplierToken: string;
  stores: CatalogStore[];
  /**
   * Цілодобова філія-пісочниця: єдина, де вікно відмітки «На місці» відкрите
   * о будь-якій годині (розділ 8, D-04). Тільки для точок, які тест доводить
   * до «На місці»; решта лишається на київських філіях.
   */
  arrivalStores: CatalogStore[];
  /** Водій із трьома точками: дві на сьогодні, одна на завтра. */
  driver: TestDriver;
  /** Водій без жодного маршрутного листа — порожній стан і чужі точки. */
  emptyDriver: TestDriver;
  today: string;
  tomorrow: string;
  early: TestBooking;
  late: TestBooking;
  next: TestBooking;
}

let shared: Shared;

test.beforeAll(async () => {
  const ctx = await api();
  const supplierToken = await supplierAuth(ctx);
  const stores = await kyivStores(ctx, supplierToken);
  const arrivalStores = await arrivalSandbox(ctx, supplierToken);
  const label = mark();
  const driver = await createDriver(ctx, supplierToken, label);
  const emptyDriver = await createDriver(ctx, supplierToken, `${label}-порожній`);

  const today = kyivDateKey();
  const tomorrow = addDays(today, 1);

  // Пізню точку створюємо ПЕРШОЮ: якщо застосунок не сортує за часом,
  // порядок на екрані повторить порядок створення — і тест це побачить.
  const late = await createBooking(ctx, supplierToken, {
    date: today, driverId: driver.id, label: `${label}-B`, which: 'last', palletsCount: 8, stores,
  });
  const early = await createBooking(ctx, supplierToken, {
    date: today, driverId: driver.id, label: `${label}-A`, which: 'first', palletsCount: 12, stores,
  });
  const next = await createBooking(ctx, supplierToken, {
    date: tomorrow, driverId: driver.id, label: `${label}-C`, which: 'first', palletsCount: 5, stores,
  });

  shared = {
    ctx, supplierToken, stores, arrivalStores,
    driver, emptyDriver, today, tomorrow, early, late, next,
  };
});

/**
 * Постачальник має ліміт 50 активних бронювань. Без прибирання набір
 * запускається рівно один раз, тому кожен прогін звільняє свої слоти.
 */
test.afterAll(async () => {
  if (!shared) return;
  const staffToken = await storeStaffAuth(shared.ctx);
  const result = await releaseBookings(shared.ctx, shared.supplierToken, staffToken);
  const drivers = await releaseDrivers(shared.ctx, shared.supplierToken);
  console.log(
    `[UITEST] прибрано: скасовано ${result.cancelled} бронювань, завершено ${result.completed}, ` +
      `лишилося ${result.left}; деактивовано водіїв ${drivers}`,
  );
});

// ---------------------------------------------------------------------------
// D-01. Вхід за телефоном
// ---------------------------------------------------------------------------

test.describe('D-01 Вхід водія', () => {
  /** Один і той самий номер у всіх формах, які має приймати AUTH-23/DRV-06. */
  function formats(phone: string): { name: string; value: string }[] {
    const n = phone.slice(4); // 9 національних цифр
    return [
      { name: 'E.164 +380XXXXXXXXX', value: phone },
      { name: 'без плюса 380XXXXXXXXX', value: `380${n}` },
      { name: 'національний 0XX…', value: `0${n}` },
      { name: 'з пробілами', value: `+380 ${n.slice(0, 2)} ${n.slice(2, 5)} ${n.slice(5, 7)} ${n.slice(7)}` },
      { name: 'з дефісами', value: `0${n.slice(0, 2)}-${n.slice(2, 5)}-${n.slice(5, 7)}-${n.slice(7)}` },
      { name: 'з дужками', value: `(0${n.slice(0, 2)}) ${n.slice(2, 5)}-${n.slice(5, 7)}-${n.slice(7)}` },
    ];
  }

  for (const index of [0, 1, 2, 3, 4, 5]) {
    test(`D-01 телефон приймається у форматі ${['E.164', 'без плюса', 'національний 0XX', 'з пробілами', 'з дефісами', 'з дужками'][index]}`, async ({ page }) => {
      const format = formats(shared.driver.phone)[index];
      const status = await driverLoginUi(page, format.value, shared.driver.password);
      expect(status, `вхід номером «${format.value}» (${format.name})`).toBe(200);
      await page.waitForURL(/\/route$/, { timeout: 30_000 });
      expect(await pageText(page)).toContain('Маршрутний лист');
    });
  }

  test('D-01 невалідний пароль — зрозуміла відмова і водій лишається на вході', async ({ page }) => {
    // Окремий водій: п'ять невдалих спроб блокують обліковий запис на 15 хв
    // (LoginThrottle), і псувати основного водія тесту не можна.
    const victim = await createDriver(shared.ctx, shared.supplierToken, `${mark()}-пароль`);

    const status = await driverLoginUi(page, victim.phone, 'ЗовсімНеТойПароль1');
    expect(status, 'бекенд має відхилити невірний пароль').toBe(401);

    await expect(page.locator('.alert[role=alert]')).toBeVisible();
    const message = await page.locator('.alert[role=alert]').innerText();
    expect(message, 'повідомлення має бути українським і зрозумілим').toMatch(/[А-Яа-яІіЇїЄєҐґ]/);
    expect(message).not.toMatch(/[a-z]{4,}/i);
    expect(page.url(), 'без пароля вхід не відбувається').toContain('/login');
  });

  test('D-01 невалідний формат телефону відхиляється до запиту', async ({ page }) => {
    await page.goto(HOSTS.driver + '/login');
    await page.waitForSelector('input[type=tel]');
    await page.locator('input[type=tel]').fill('12345');
    await page.locator('input[type=password]').fill('будь-який');
    await page.locator('button[type=submit]').click();

    await expect(page.locator('.field-error').first()).toBeVisible();
    expect(await pageText(page)).toContain('Невірний формат телефону');
  });

  test('D-01 довга сесія: після перезавантаження водій лишається в застосунку', async ({ page }) => {
    await openRouteSheet(page, shared.driver);

    await page.reload();
    await page.waitForLoadState('networkidle');
    expect(page.url(), 'перезавантаження не має викидати на екран входу').toContain('/route');
    expect(await pageText(page)).toContain('Маршрутний лист');

    // Друга вкладка того самого пристрою — сесія теж має бути жива.
    const second = await page.context().newPage();
    await second.goto(HOSTS.driver + '/');
    await second.waitForLoadState('networkidle');
    expect(second.url(), 'сесія має жити на рівні пристрою, а не вкладки').toContain('/route');
    await second.close();
  });

  test('D-01/X-09 прямий перехід на маршрутний лист без сесії веде на вхід', async ({ page }) => {
    await page.goto(HOSTS.driver + '/route');
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain('/login');
    await expect(page.locator('input[type=tel]')).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// D-02. Маршрутний лист
// ---------------------------------------------------------------------------

test.describe('D-02 Маршрутний лист', () => {
  test('D-02 точки йдуть у хронологічному порядку', async ({ page }) => {
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    const points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    expect(points.length, 'для перевірки порядку потрібно щонайменше дві точки').toBeGreaterThanOrEqual(2);

    const expectedOrder = [...points]
      .sort((a, b) => String(a.slotStart).localeCompare(String(b.slotStart)))
      .map((p) => String(p.bookingId));

    await openRouteSheet(page, shared.driver);
    const shownOrder = await page.locator('ul.points > li').evaluateAll((nodes) =>
      nodes.map((n) => (n.getAttribute('id') ?? '').replace(/^point-/, '')),
    );

    expect(shownOrder, 'на екрані має бути стільки ж точок, скільки в API').toHaveLength(expectedOrder.length);
    expect(shownOrder, 'точки мають бути відсортовані за часом слоту').toEqual(expectedOrder);
  });

  test('D-02 картка точки містить усі дані з маршрутного листа', async ({ page }) => {
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    const points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    const point = points.find((p) => p.bookingId === shared.early.bookingId);
    expect(point, 'бронювання тесту має бути в маршрутному листі').toBeTruthy();

    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, shared.early.bookingId);
    await expect(card).toBeVisible();
    const text = (await card.innerText()).replace(/\s+/g, ' ');

    const required: [string, string][] = [
      ['час слоту', String(point!.localTime)],
      ['назва філії', String(point!.storeName)],
      ['місто', String(point!.city)],
      ['адреса', String(point!.address)],
      ['кількість палет', String(point!.palletsCount)],
      ['держномер', String(point!.plateNumber)],
      ['номер замовлення', String(point!.orderId)],
      ['статус', 'Очікує виїзду'],
    ];
    for (const [field, value] of required) {
      expect(text, `картка має показувати ${field}: «${value}»`).toContain(value);
    }
  });

  test('D-02/X-07 картка не показує технічних ідентифікаторів замість людських назв', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, shared.early.bookingId);
    const text = (await card.innerText()).replace(/\s+/g, ' ');

    const ramp = shared.early.store.ramps?.find((r) => r.rampId === shared.early.rampId);
    expect(
      text,
      `водій має бачити людський номер рампи (${ramp ? `«${ramp.name}», номер ${ramp.number}` : 'з каталогу філії'}), ` +
        `а не внутрішній ідентифікатор «${shared.early.rampId}»`,
    ).not.toContain(shared.early.rampId);
  });

  test('D-02 перемикання дат: «Сьогодні» ↔ «Завтра»', async ({ page }) => {
    await openRouteSheet(page, shared.driver);

    const chips = page.locator('nav.chips button.chip');
    await expect(chips, 'мають бути чипси обох дат із поїздками').toHaveCount(2);
    const labels = await chips.allInnerTexts();
    expect(labels.join(' ')).toContain('Сьогодні');
    expect(labels.join(' ')).toContain('Завтра');

    // Лічильник у чипі має збігатися з фактичною кількістю точок дати.
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    const todayPoints = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    const tomorrowPoints = await driverRouteSheet(shared.ctx, driverToken, shared.tomorrow);
    expect(labels.find((l) => l.includes('Сьогодні'))).toContain(String(todayPoints.length));
    expect(labels.find((l) => l.includes('Завтра'))).toContain(String(tomorrowPoints.length));

    await page.locator('nav.chips button.chip', { hasText: 'Завтра' }).click();
    await page.waitForLoadState('networkidle');
    await expect(pointCard(page, shared.next.bookingId), 'після перемикання видно точку завтрашнього дня').toBeVisible();
    await expect(pointCard(page, shared.early.bookingId), 'точки іншої дати мають зникнути').toHaveCount(0);

    await page.locator('nav.chips button.chip', { hasText: 'Сьогодні' }).click();
    await page.waitForLoadState('networkidle');
    await expect(pointCard(page, shared.early.bookingId)).toBeVisible();
  });

  test('D-02/X-04 порожній маршрутний лист має свідоме повідомлення', async ({ page }) => {
    await openRouteSheet(page, shared.emptyDriver);
    const text = await pageText(page);
    expect(text, 'порожній стан має бути пояснений, а не порожнім екраном').toContain(
      'Маршрутних листів поки немає',
    );
    await expect(page.locator('ul.points > li')).toHaveCount(0);
  });

  test('D-02/X-07 в інтерфейсі немає неперекладених ключів', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const text = await pageText(page);
    const keys = untranslatedFragments(text);
    expect(keys, `на екрані видно ключі перекладу: ${keys.join(', ')}`).toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// D-03. Навігація
// ---------------------------------------------------------------------------

test.describe('D-03 Побудувати маршрут', () => {
  /** Координати філії з каталогу — те, що навігатор мав би отримати. */
  function coords(store: CatalogStore): { lat: string; lon: string } {
    expect(store.latitude, `у філії ${store.externalId} мають бути координати`).not.toBeNull();
    return { lat: String(store.latitude), lon: String(store.longitude) };
  }

  test('D-03 кнопка відкриває вибір навігатора', async ({ page }) => {
    await captureExternalOpens(page);
    await openRouteSheet(page, shared.driver);

    await pointCard(page, shared.early.bookingId).locator('button.btn.primary.tall').click();
    const sheet = page.locator('section.sheet[role=dialog]');
    await expect(sheet).toBeVisible();
    const options = await sheet.innerText();
    expect(options).toContain('Google Maps');
    expect(options).toContain('Waze');
  });

  test('D-03 посилання Google Maps веде на РЕАЛЬНІ координати філії', async ({ page }) => {
    await captureExternalOpens(page);
    await openRouteSheet(page, shared.driver);

    await pointCard(page, shared.early.bookingId).locator('button.btn.primary.tall').click();
    await page.locator('section.sheet button', { hasText: 'Google Maps' }).click();

    const urls = await openedUrls(page);
    expect(urls, 'вибір навігатора має відкрити посилання').toHaveLength(1);
    const url = decodeURIComponent(urls[0]);
    expect(url).toContain('google.com/maps');

    const { lat, lon } = coords(shared.early.store);
    expect(
      url,
      `Google Maps має отримати координати філії ${shared.early.store.externalId} (${lat},${lon}), ` +
        `фактично відкрито: ${url}`,
    ).toContain(lat);
    expect(url).toContain(lon);
  });

  test('D-03 посилання Waze веде на РЕАЛЬНІ координати філії', async ({ page }) => {
    await captureExternalOpens(page);
    await openRouteSheet(page, shared.driver);

    await pointCard(page, shared.early.bookingId).locator('button.btn.primary.tall').click();
    await page.locator('section.sheet button', { hasText: 'Waze' }).click();

    const urls = await openedUrls(page);
    expect(urls).toHaveLength(1);
    const url = decodeURIComponent(urls[0]);
    expect(url).toContain('waze.com');

    const { lat, lon } = coords(shared.early.store);
    expect(
      url,
      `Waze має отримати координати філії ${shared.early.store.externalId} (${lat},${lon}), ` +
        `фактично відкрито: ${url}`,
    ).toContain(lat);
    expect(url).toContain(lon);
  });

  test('D-03 обраний навігатор запамʼятовується', async ({ page }) => {
    await captureExternalOpens(page);
    await openRouteSheet(page, shared.driver);

    const card = pointCard(page, shared.early.bookingId);
    await card.locator('button.btn.primary.tall').click();
    await page.locator('section.sheet button', { hasText: 'Waze' }).click();
    await expect(page.locator('section.sheet[role=dialog]')).toHaveCount(0);

    // Другий раз вибір повторно не питають — одразу відкривається Waze.
    await card.locator('button.btn.primary.tall').click();
    const urls = await openedUrls(page);
    expect(urls, 'повторне натискання відкриває запамʼятований навігатор без питань').toHaveLength(2);
    expect(urls[1]).toContain('waze.com');
  });
});

// ---------------------------------------------------------------------------
// D-04 + D-05. «На місці» і номер замовлення (спільний ланцюжок станів)
// ---------------------------------------------------------------------------

test.describe('D-04/D-05 «На місці» і номер замовлення', () => {
  // Ланцюжок навмисно послідовний: booked → orderId → arrived → магазин
  // починає розвантаження → orderId заблоковано. Це один життєвий цикл
  // точки, і розривати його на незалежні тести означало б перевіряти щось
  // інше, ніж працює насправді.
  test.describe.configure({ mode: 'serial' });

  let booking: TestBooking;
  let driverToken: string;
  let staffToken: string;

  test.beforeAll(async () => {
    // Ланцюжок доводить точку до «На місці», а відмітка приймається лише
    // у вікні «slotStart − 60 хв … кінець слоту» (розділ 8, D-04). Тому
    // бронюємо найближчий слот у цілодобовій філії-пісочниці — єдиній, де
    // таке вікно відкрите о будь-якій годині (див. arrivalSandbox).
    booking = await createBooking(shared.ctx, shared.supplierToken, {
      date: shared.today,
      driverId: shared.driver.id,
      label: `${mark()}-цикл`,
      which: 'soonest',
      stores: shared.arrivalStores,
    });
    driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    staffToken = await storeStaffAuth(shared.ctx);
  });

  test('D-05 водій вводить номер замовлення', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();

    await card.locator('button', { hasText: /№ замовлення/ }).click();
    const input = card.locator('input.order-input');
    await expect(input).toBeVisible();

    const value = `${booking.orderId}-РЕД`;
    await input.fill(value);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}`) && r.request().method() === 'PATCH'),
      card.locator('button', { hasText: 'Зберегти' }).click(),
    ]);
    expect(response.status(), 'бекенд має прийняти номер від водія').toBe(200);

    await expect(card).toContainText(value);

    // Звірка з джерелом істини, а не з екраном.
    const points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    const stored = points.find((p) => p.bookingId === booking.bookingId);
    expect(stored?.orderId, 'номер має зберегтися в маршрутному листі').toBe(value);
  });

  test('D-04 «На місці» переводить точку у статус «На місці»', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();

    const arrive = card.locator('button.btn.arrive');
    await expect(arrive, 'для точки у статусі «Очікує виїзду» кнопка має бути').toBeVisible();

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}/arrived`)),
      arrive.click(),
    ]);
    expect(response.status()).toBe(200);

    // Підтвердження водієві: статус на картці змінився.
    await expect(card.locator('.badge')).toHaveText('На місці');
    await expect(card.locator('button.btn.arrive'), 'повторно натиснути вже нічим').toHaveCount(0);
  });

  test('D-04 повторна відмітка не ламає стан', async () => {
    const res = await shared.ctx.post(`${HOSTS.driver}/api/driver/v1/bookings/${booking.bookingId}/arrived`, {
      headers: { Authorization: `Bearer ${driverToken}` },
      data: {},
    });
    expect(res.status(), 'дія ідемпотентна: повтор повертає поточний стан').toBe(200);
    expect((await res.json()).status).toBe('arrived');
  });

  test('D-04 результат відмітки видно в контурі магазину', async () => {
    // Читальних маршрутів у контурі магазину немає, тому статус «очима
    // магазину» видно через відмову повторного переходу arrived → arrived.
    const seen = await storeSeesStatus(shared.ctx, staffToken, booking.bookingId);
    expect(seen.status, `магазин має бачити бронювання прибулим, отримано ${JSON.stringify(seen)}`).toBe(409);
    expect(seen.from).toBe('arrived');

    // Найпряміший доказ: магазин може почати розвантаження, а це можливо
    // рівно зі статусу arrived.
    expect(await storeStartUnloading(shared.ctx, staffToken, booking.bookingId)).toBe(200);
  });

  test('D-05 після початку розвантаження водій не може змінити номер замовлення', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();
    await expect(card.locator('.badge')).toHaveText('Розвантаження');

    await expect(
      card.locator('button', { hasText: /№ замовлення/ }),
      'редагування номера має зникнути після початку розвантаження',
    ).toHaveCount(0);

    // І бекенд теж має відхиляти — щоб заборона не трималася лише на UI.
    const res = await shared.ctx.patch(`${HOSTS.driver}/api/driver/v1/bookings/${booking.bookingId}`, {
      headers: { Authorization: `Bearer ${driverToken}` },
      data: { orderId: 'UITEST-ПІСЛЯ-РОЗВАНТАЖЕННЯ' },
    });
    expect(res.status()).toBe(422);
    expect((await res.json()).detail).toContain('до початку розвантаження');
  });
});

// ---------------------------------------------------------------------------
// D-04. Доступність «На місці» за часом
// ---------------------------------------------------------------------------

test.describe('D-04 доступність відмітки за часом', () => {
  test('D-04 відмітити прибуття на завтрашню точку не можна', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    await page.locator('nav.chips button.chip', { hasText: 'Завтра' }).click();
    await page.waitForLoadState('networkidle');

    const card = pointCard(page, shared.next.bookingId);
    await expect(card).toBeVisible();
    expect(
      await card.locator('button.btn.arrive').count(),
      `точка запланована на ${shared.next.date} о ${shared.next.localTime}; ` +
        'відмітка «На місці» за добу до слоту створює хибне прибуття в черзі магазину',
    ).toBe(0);
  });

  test('D-04 бекенд теж не приймає прибуття на завтрашню точку', async () => {
    const probe = await createBooking(shared.ctx, shared.supplierToken, {
      date: shared.tomorrow,
      driverId: shared.driver.id,
      label: `${mark()}-рано`,
      which: 'last',
      stores: shared.stores,
    });
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    const res = await shared.ctx.post(`${HOSTS.driver}/api/driver/v1/bookings/${probe.bookingId}/arrived`, {
      headers: { Authorization: `Bearer ${driverToken}` },
      data: {},
    });
    expect(
      res.status(),
      `бронювання на ${probe.date} ${probe.localTime}: відмітка прибуття за добу наперед має бути відхилена, ` +
        `отримано ${res.status()}`,
    ).not.toBe(200);
  });
});

// ---------------------------------------------------------------------------
// D-06. Затримка
// ---------------------------------------------------------------------------

test.describe('D-06 Повідомлення про затримку', () => {

  let booking: TestBooking;

  test.beforeAll(async () => {
    booking = await createBooking(shared.ctx, shared.supplierToken, {
      date: shared.today,
      driverId: shared.driver.id,
      label: `${mark()}-затримка`,
      which: 'last',
      stores: shared.stores,
    });
  });

  test('D-06 причина з довідника і новий час прибуття', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();
    await card.locator('button', { hasText: 'Повідомити про затримку' }).click();

    const sheet = page.locator('section.sheet[role=dialog]');
    await expect(sheet).toBeVisible();
    const sheetText = await sheet.innerText();
    for (const reason of ['Затори', 'Поломка', 'Затримка на попередній точці', 'Інше']) {
      expect(sheetText, `у довіднику причин має бути «${reason}»`).toContain(reason);
    }

    await sheet.locator('button.sheet-item', { hasText: 'Затори' }).click();
    await sheet.locator('button.chip', { hasText: '+30 хв' }).click();

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}/delay`)),
      sheet.locator('button.sheet-item.primary').click(),
    ]);
    expect(response.status(), `бекенд відповів: ${await response.text()}`).toBe(200);

    const body = await response.json();
    expect(body.delayed?.flag, 'бронювання має бути позначене як затримане').toBe(true);
    expect(body.delayed?.reason).toBe('затори');
    expect(Date.parse(body.delayed?.eta), 'новий час прибуття має бути в майбутньому').toBeGreaterThan(Date.now());

    await expect(page.locator('.toast')).toContainText('Затримку передано магазину');
    await expect(card).toContainText('Затримка');
  });

  test('D-06 причина «Інше» вимагає коментаря', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();
    await card.locator('button', { hasText: 'Повідомити про затримку' }).click();

    const sheet = page.locator('section.sheet[role=dialog]');
    await sheet.locator('button.sheet-item', { hasText: 'Інше' }).click();
    await sheet.locator('button.chip', { hasText: '+1 год' }).click();

    const textarea = sheet.locator('textarea.sheet-input');
    await expect(textarea, 'для «Інше» має зʼявитися поле коментаря').toBeVisible();
    await expect(
      sheet.locator('button.sheet-item.primary'),
      'без коментаря надіслати не можна',
    ).toBeDisabled();

    const comment = 'UITEST: об’їзд через перекриту вулицю';
    await textarea.fill(comment);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}/delay`)),
      sheet.locator('button.sheet-item.primary').click(),
    ]);
    expect(response.status(), `бекенд відповів: ${await response.text()}`).toBe(200);

    const body = await response.json();
    expect(body.delayed?.reason, 'коментар має дійти до магазину разом із причиною').toContain(comment);
    await expect(page.locator('.toast')).toContainText('Затримку передано магазину');
  });
});

// ---------------------------------------------------------------------------
// D-07. Офлайн
// ---------------------------------------------------------------------------

test.describe('D-07 Робота без звʼязку', () => {

  let booking: TestBooking;

  test.beforeAll(async () => {
    // Набір доводить точку до «На місці» через офлайн-чергу, тож слот має
    // бути у вікні відмітки (розділ 8, D-04) — беремо цілодобову пісочницю.
    booking = await createBooking(shared.ctx, shared.supplierToken, {
      date: shared.today,
      driverId: shared.driver.id,
      label: `${mark()}-офлайн`,
      which: 'soonest',
      stores: shared.arrivalStores,
    });
  });

  test('D-07 кешований маршрутний лист відкривається без мережі', async ({ page, context }) => {
    await openRouteSheet(page, shared.driver);
    await expect(pointCard(page, booking.bookingId)).toBeVisible();

    await context.setOffline(true);
    await page.reload();
    await page.waitForSelector('ul.points > li', { timeout: 30_000 });

    await expect(pointCard(page, booking.bookingId), 'кешований лист має відкриватися офлайн').toBeVisible();
    await context.setOffline(false);
  });

  test('D-07 без звʼязку водій попереджений, що дані збережені, а не свіжі', async ({ page, context }) => {
    await openRouteSheet(page, shared.driver);

    await context.setOffline(true);
    await page.reload();
    await page.waitForSelector('ul.points > li', { timeout: 30_000 });

    // Маршрут показано без мережі — це добре. Погано, якщо водій цього не знає:
    // «Оновлено HH:MM» на збережених даних читається як «щойно з сервера».
    const text = await pageText(page);
    await expect(
      page.locator('.banner.offline'),
      `екран без звʼязку не позначений як збережений; показано: «${text.slice(0, 200)}»`,
    ).toBeVisible();
    await context.setOffline(false);
  });

  test('D-07 відмітка без мережі стає в чергу і йде на сервер після відновлення', async ({ page, context }) => {
    await openRouteSheet(page, shared.driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();

    await context.setOffline(true);
    await card.locator('button.btn.arrive').click();

    await expect(card, 'водій має отримати підтвердження, а не мовчання').toContainText(
      'Відмітку збережено',
    );
    await expect(page.locator('.banner.queued')).toContainText('чекають на звʼязок');

    // Сервер відмітки ще не бачив.
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    let points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    expect(points.find((p) => p.bookingId === booking.bookingId)?.status).toBe('booked');

    await context.setOffline(false);
    await expect(page.locator('.banner.queued'), 'черга має спорожніти після відновлення звʼязку').toHaveCount(0, {
      timeout: 45_000,
    });

    points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    expect(
      points.find((p) => p.bookingId === booking.bookingId)?.status,
      'відкладена відмітка має дійти до сервера',
    ).toBe('arrived');
  });
});

// ---------------------------------------------------------------------------
// RT-04. Автооновлення маршрутного листа
// ---------------------------------------------------------------------------

test.describe('RT-04 Автооновлення маршрутного листа', () => {
  test('RT-04 нова точка зʼявляється у водія без перезаходу', async ({ page }) => {
    // Полінг за замовчуванням раз на 30 с (environment.pollIntervalMs).
    test.setTimeout(150_000);

    await openRouteSheet(page, shared.driver);
    const before = await page.locator('ul.points > li').count();
    expect(before, 'на початку у водія вже є точки').toBeGreaterThan(0);

    const extra = await createBooking(shared.ctx, shared.supplierToken, {
      date: shared.today,
      driverId: shared.driver.id,
      label: `${mark()}-полінг`,
      which: 'last',
      stores: shared.stores,
    });

    // Джерело істини: у листі вже на одну точку більше.
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    const points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
    expect(points.map((p) => p.bookingId)).toContain(extra.bookingId);

    await expect(
      pointCard(page, extra.bookingId),
      `постачальник додав точку ${extra.orderId} на ${extra.localTime}; ` +
        'водій має побачити її з чергового оновлення, не перезаходячи в застосунок',
    ).toBeVisible({ timeout: 90_000 });
  });
});

// ---------------------------------------------------------------------------
// D-09. Обмеження прав
// ---------------------------------------------------------------------------

test.describe('D-09 Обмеження прав водія', () => {
  test('D-09 водій не бачить чужих точок', async ({ page }) => {
    await openRouteSheet(page, shared.emptyDriver);
    const text = await pageText(page);
    expect(text, 'чуже бронювання не має потрапляти на екран').not.toContain(shared.early.orderId);
    expect(text).not.toContain(shared.early.plateNumber);
  });

  test('D-09 дії над чужою точкою відхиляються', async () => {
    const foreign = await driverAuth(shared.ctx, shared.emptyDriver.phone, shared.emptyDriver.password);
    const res = await shared.ctx.post(
      `${HOSTS.driver}/api/driver/v1/bookings/${shared.early.bookingId}/arrived`,
      { headers: { Authorization: `Bearer ${foreign}` }, data: {} },
    );
    expect(res.status(), 'чуже бронювання має бути недосяжним').toBe(403);
  });

  test('D-09 водієві недоступні дії магазину', async () => {
    const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
    const res = await shared.ctx.post(
      `${HOSTS.store}/api/store/v1/bookings/${shared.early.bookingId}/unloading`,
      { headers: { Authorization: `Bearer ${driverToken}` }, data: {} },
    );
    expect([401, 403, 404], `отримано ${res.status()}`).toContain(res.status());
  });

  test('D-09/X-10 маршрутний лист не має горизонтального скролу на десктопі', async ({ page }) => {
    await openRouteSheet(page, shared.driver);
    expect(await hasHorizontalScroll(page)).toBe(false);
  });
});
