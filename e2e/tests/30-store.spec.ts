/**
 * Модуль магазину (store.) — сценарії M-01…M-08 плану UI-тестування
 * плюс наскрізні перевірки X-01, X-05, X-07, X-09, X-10 на його екранах.
 *
 * Метод: дані готуються через API ПОСТАЧАЛЬНИКА (hold → booking), перевіряються
 * через ІНТЕРФЕЙС МАГАЗИНУ. Очікувані значення списків і лічильників беруться
 * з API, а не з голови автора, — інакше тест закріпить той самий зріз, який
 * показує інтерфейс.
 *
 * Робота ведеться на філіях-пісочницях Харкова (2226, 2227, 2229, 2230), а
 * перевірки, які доводять бронювання до «На місці», — на цілодобовій філії
 * 2231: вікно відмітки (розділ 8) у денної філії вночі просто зачинене.
 */
import { APIRequestContext, expect, Page, request, test } from '@playwright/test';
import { CREDS, HOSTS, hasHorizontalScroll, loginUi, pageText, registerArtifact } from '../support/env';
import {
  arrivalSandboxStore,
  Booking,
  createBooking,
  DELAY_REASONS,
  kyivDateKey,
  kyivTime,
  MadeBooking,
  PARTIAL_UNLOAD_REASONS,
  readBooking,
  REJECT_REASONS,
  sandboxStores,
  SandboxStore,
  slotGrid,
  storeLogin,
  supplierAccessToken,
  testPlate,
} from '../support/store-fixtures';

// ---------------------------------------------------------------------------
// Спільний контекст прогону
// ---------------------------------------------------------------------------

let ctx: APIRequestContext;
let supplierToken: string;
let stores: SandboxStore[];
/**
 * Цілодобова філія-пісочниця. Відмітку «На місці» домен приймає лише у вікні
 * «slotStart − 60 хв … кінець слоту» (розділ 8), а прийом звичайної філії
 * денний — тож нічний прогін не відмітив би прибуття взагалі. Усе, що
 * доводить бронювання до «На місці», створюється саме тут.
 */
let arrivalStore: SandboxStore;
/** Усе створене — щоб прибрати наприкінці. */
const created: string[] = [];

test.beforeAll(async () => {
  ctx = await request.newContext({ ignoreHTTPSErrors: true });
  supplierToken = await supplierAccessToken(ctx);
  stores = await sandboxStores(ctx, supplierToken);
  arrivalStore = await arrivalSandboxStore(ctx, supplierToken);
});

test.afterAll(async () => {
  // Прибирання за принципом «як вийде»: бронювання в термінальному статусі
  // скасувати вже неможливо, і це не помилка.
  for (const id of created) {
    await ctx
      .delete(`${HOSTS.supplier}/api/supplier/v1/bookings/${id}`, {
        headers: { Authorization: `Bearer ${supplierToken}` },
        data: { reason: 'UITEST cleanup' },
      })
      .catch(() => undefined);
  }
  await ctx.dispose();
});

/**
 * Робоча філія набору. Дошка магазину показує рівно один магазин, тому всі
 * бронювання створюються на одній філії — інакше тест шукав би картку не там,
 * де вона є. Потрібні 2+ рампи (переведення на іншу рампу, фільтр за рампою).
 */
function primaryStore(): SandboxStore {
  const withRamps = stores.filter((s) => s.ramps.filter((r) => r.active !== false).length > 1);
  expect(withRamps.length, 'у пісочниці має бути філія з 2+ рампами').toBeGreaterThan(0);
  return withRamps.sort((a, b) => b.ramps.length - a.ramps.length)[0];
}

/**
 * Філії для бронювання: спершу робоча, потім цілодобова.
 *
 * Звичайна пісочниця приймає до 16:00, тож увечері вільних слотів на сьогодні
 * там уже немає — і набір падав не через дефект, а через годинник. Цілодобова
 * філія (00:00–23:45, 6 рамп) закриває будь-яку годину доби; на неї ж
 * розраховані перевірки прибуття.
 */
function bookingStores(target: SandboxStore): SandboxStore[] {
  return target.storeId === arrivalStore.storeId ? [target] : [target, arrivalStore];
}

async function makeBooking(
  label: string,
  options: {
    dateKey?: string;
    palletsCount?: number;
    skipSlots?: number;
    rampId?: string;
    store?: SandboxStore;
  } = {},
): Promise<MadeBooking> {
  const target = options.store ?? primaryStore();
  const booking = await createBooking(ctx, supplierToken, bookingStores(target), {
    label,
    ...options,
  });
  created.push(booking.id);
  return booking;
}

// ---------------------------------------------------------------------------
// UI-помічники
// ---------------------------------------------------------------------------

async function storeSignIn(page: Page): Promise<void> {
  await loginUi(page, HOSTS.store, {
    '#email': CREDS.admin.email,
    '#password': CREDS.admin.password,
  });
}

/** Напис, яким застосунок позначає ще не добудований зріз. */
const LOADING_TEXT = 'Завантаження…';

/**
 * Чекає, доки екран добудує зріз.
 *
 * `page.waitForLoadState('networkidle')` для цього не годиться: всередині
 * застосунку навігації немає, тому Playwright віддає стан ОСТАННЬОГО переходу
 * і повертається миттєво — ще до того, як застосунок устиг послати запит.
 * Знімок, зроблений одразу після дії, показував не результат дії, а те, що
 * лишилося на екрані від попереднього зрізу.
 */
async function waitForScreen(page: Page): Promise<void> {
  await expect(
    page.locator('.page > p.muted').filter({ hasText: LOADING_TEXT }),
    'екран не має лишатися в стані завантаження',
  ).toHaveCount(0, { timeout: 30_000 });
}

/** Виконує дію і чекає саме на ту відповідь дошки, яку ця дія має спричинити. */
async function withBoardResponse(
  page: Page,
  matches: (url: string) => boolean,
  action: () => Promise<void>,
): Promise<void> {
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('/api/store/v1/bookings?') && matches(r.url()),
      { timeout: 30_000 },
    ),
    action(),
  ]);
  await waitForScreen(page);
}

/** Кнопка швидкого переходу дати («Вчора» / «Сьогодні» / «Завтра»). */
async function goToDate(page: Page, label: string, dateKey: string): Promise<void> {
  await withBoardResponse(
    page,
    (url) => url.includes(`date=${dateKey}`),
    async () => {
      await page.getByRole('button', { name: label, exact: true }).first().click();
    },
  );
}

/** Перезавантаження сторінки разом із очікуванням дошки. */
async function reloadBoard(page: Page): Promise<void> {
  await page.reload();
  await page.waitForLoadState('networkidle');
  await waitForScreen(page);
}

/** Відкриває форму позапланового прибуття і дочікується сітки вільних слотів. */
async function openWalkIn(page: Page) {
  await Promise.all([
    page.waitForResponse((r) => /\/stores\/[^/]+\/slots\?date=/.test(r.url()), {
      timeout: 30_000,
    }),
    page.getByRole('button', { name: 'Позапланове прибуття' }).click(),
  ]);
  const dialog = page.locator('[role=dialog]');
  await expect(dialog, 'форма позапланового прибуття має відкритися').toBeVisible();
  await expect(
    dialog.locator('.muted').filter({ hasText: LOADING_TEXT }),
    'форма не має лишатися з незавантаженою сіткою слотів',
  ).toHaveCount(0, { timeout: 30_000 });
  return dialog;
}

/**
 * Вхід + гарантія, що ми справді на дошці. Якщо застосунок відмовив у доступі
 * або дошка не завантажилась, тест падає тут із поясненням причини.
 */
async function openBoard(page: Page, store?: SandboxStore): Promise<void> {
  await storeSignIn(page);
  const url = page.url();
  const seen = (await pageText(page)).slice(0, 200);
  expect(
    url,
    `співробітник із правами магазину має потрапити на дошку «Сьогодні», ` +
      `а опинився на ${url}; екран показує: «${seen}»`,
  ).toContain('/today');

  await page.waitForSelector('h1', { timeout: 15_000 });
  await page.waitForLoadState('networkidle');
  await waitForScreen(page);

  const text = await pageText(page);
  expect(
    text,
    'дошка не має відкриватися з повідомленням про недоступний розділ',
  ).not.toContain('тимчасово недоступний');
  expect(text, 'на дошці не має бути повідомлення про помилку завантаження').not.toContain(
    'Сталася помилка',
  );

  if (store) await selectStore(page, store);
}

/**
 * Перемикач філії — не `<select>`, а панель із пошуком: у керівника мережі
 * філій сотні. Помічники нижче ховають цю механіку від самих перевірок.
 */
async function pickerLabel(page: Page): Promise<string> {
  // Саме підпис, а не вся кнопка: у неї ще й стрілка, яка до назви не належить.
  return (await page.locator('.picker__value').innerText()).trim();
}

/** Відкриває панель і повертає підписи всіх філій (за потреби — з пошуком). */
async function pickerOptions(page: Page, query = ''): Promise<string[]> {
  await page.locator('.picker__trigger').click();
  if (query) {
    await page.locator('.picker__search').fill(query);
  }
  await page.waitForTimeout(150);
  const labels = await page.locator('.picker__option').allInnerTexts();
  await page.keyboard.press('Escape');
  return labels.map((s) => s.trim());
}

/** Ставить дошку на потрібну філію (або перевіряє, що вона вже на ній). */
async function selectStore(page: Page, store: SandboxStore): Promise<void> {
  const trigger = page.locator('.picker__trigger');
  if (await trigger.count()) {
    // Уже на потрібній філії — перемикати нема чого (і події теж не буде).
    if ((await pickerLabel(page)).includes(store.externalId)) {
      return;
    }

    await trigger.click();
    // Шукаємо за кодом філії: він унікальний, на відміну від назви й адреси.
    await page.locator('.picker__search').fill(store.externalId);
    const option = page.locator('.picker__option', { hasText: store.externalId }).first();
    await expect(
      option,
      `у перемикачі має знаходитися філія ${store.externalId} (${store.address})`,
    ).toBeVisible();

    await withBoardResponse(
      page,
      (url) => url.includes(`storeId=${store.storeId}`),
      async () => {
        await option.click();
      },
    );
    // Перемикач має показувати ту саму філію, дані якої лягли на дошку.
    expect(
      await pickerLabel(page),
      `перемикач має стояти на філії ${store.externalId}, дані якої показує дошка`,
    ).toContain(store.externalId);
  } else {
    const label = await page.locator('.appbar__storename').innerText().catch(() => '');
    expect(
      label,
      `дошка має показувати філію ${store.externalId}, а показує «${label}»`,
    ).toContain(store.externalId);
  }
}

/** Переводить дошку на потрібну дату через календарне поле. */
async function setBoardDate(page: Page, dateKey: string): Promise<void> {
  const picker = page.locator('input[type=date]').first();
  await withBoardResponse(
    page,
    (url) => url.includes(`date=${dateKey}`),
    async () => {
      await picker.fill(dateKey);
      await picker.dispatchEvent('change');
    },
  );
}

/** Картка бронювання за держномером (у DOM вона є і в колонці, і в мобільному списку). */
function cardByPlate(page: Page, plate: string) {
  return page.locator('article.bcard').filter({ hasText: plate }).first();
}

/** Значення плитки денної статистики за підписом. */
async function statValue(page: Page, label: string): Promise<number> {
  const tile = page.locator('.stats__tile').filter({ hasText: label }).first();
  const value = await tile.locator('.stats__value').innerText();
  return Number(value.replace(/\D/g, ''));
}

/**
 * Тексти варіантів випадного списку (без плейсхолдера «—»).
 * Саме textContent: innerText для згорнутого <select> у частині рушіїв порожній.
 */
async function optionTexts(page: Page, selector: string): Promise<string[]> {
  return (await page.locator(`${selector} option`).allTextContents())
    .map((t) => t.trim())
    .filter((t) => t.length > 0 && t !== '—');
}

// ===========================================================================
// M-00. Передумови: що контур магазину дозволяє на рівні бекенду
//
// Ці перевірки не про інтерфейс. Вони потрібні, щоб під час розбору падінь
// відрізнити «функції немає взагалі» від «функція є, але інтерфейс до неї не
// пускає» — інакше всі падіння модуля виглядають однаково.
// ===========================================================================

test.describe('M-00 Передумови контуру магазину', () => {
  test('M-00.1 бекенд дозволяє цьому обліковому запису повний ланцюжок дій', async () => {
    test.setTimeout(120_000);
    const login = await storeLogin(ctx);
    const booking = await makeBooking('backend-chain', { store: arrivalStore });

    for (const [action, body, expected] of [
      ['arrived', {}, 'arrived'],
      ['unloading', {}, 'unloading'],
      ['completed', { unloadedPalletsCount: booking.palletsCount }, 'completed'],
    ] as const) {
      const res = await ctx.post(`${HOSTS.store}/api/store/v1/bookings/${booking.id}/${action}`, {
        headers: { Authorization: `Bearer ${login.accessToken}` },
        data: body,
      });
      expect(
        res.status(),
        `дія ${action} має бути доступною ролі ${login.user.role}: ${(await res.text()).slice(0, 160)}`,
      ).toBe(200);
      expect((await readBooking(ctx, supplierToken, booking.id)).status).toBe(expected);
    }
  });

  test('M-00.2 бекенд приймає позапланове прибуття від цього облікового запису', async () => {
    test.setTimeout(120_000);
    const login = await storeLogin(ctx);
    // Цілодобова філія: вільний слот на сьогодні є в будь-яку годину.
    const store = arrivalStore;
    const grid = await slotGrid(ctx, supplierToken, store.storeId, kyivDateKey());
    const free = grid.slots.filter((s) => s.state === 'available' && s.selectable);
    expect(free.length, 'для walk-in потрібен вільний слот на сьогодні').toBeGreaterThan(0);

    const plate = testPlate();
    const res = await ctx.post(`${HOSTS.store}/api/store/v1/bookings/walk-in`, {
      headers: { Authorization: `Bearer ${login.accessToken}` },
      data: {
        storeId: store.storeId,
        rampId: free[0].rampId,
        slotStart: free[0].slotStart,
        vehicle: { plateNumber: plate, weightTons: 6, brand: 'UITEST' },
        palletsCount: 4,
        supplierName: 'UITEST-Поза системою',
        orderId: 'UITEST-walkin-api',
      },
    });
    expect(res.status(), `walk-in: ${(await res.text()).slice(0, 200)}`).toBe(201);
    const body = (await res.json()) as Booking;
    created.push(body.id);
    registerArtifact('walk-in', body.id, `${plate} · перевірка контракту`);
    expect(body.status, 'позапланове прибуття створюється одразу «на місці»').toBe('arrived');
    expect(body.type).toBe('walk_in');
  });
});

// ===========================================================================
// M-01. Вхід і вибір магазину
// ===========================================================================

test.describe('M-01 Вхід і вибір магазину', () => {
  test('M-01.1 сторінка входу українською, з полями e-mail і пароля', async ({ page }) => {
    await page.goto(HOSTS.store + '/login');
    await page.waitForSelector('input[type=password]');

    const text = await pageText(page);
    expect(text).toContain('Вхід для персоналу магазину');
    expect(await page.locator('#email').count(), 'поле e-mail').toBe(1);
    expect(await page.locator('#password').count(), 'поле пароля').toBe(1);
    expect(text, 'кнопка входу').toContain('Увійти');

    // X-07: на бойовому стенді не має бути підказок демо-режиму й чужих логінів.
    expect(
      text,
      'сторінка входу показує підказку демо-режиму з чужими обліковими записами',
    ).not.toContain('Демо-режим');
  });

  test('M-01.2 невірні дані → зрозуміле повідомлення, вхід не відбувається', async ({ page }) => {
    await page.goto(HOSTS.store + '/login');
    await page.waitForSelector('input[type=password]');
    await page.locator('#email').fill('uitest.nobody@rampa.test');
    await page.locator('#password').fill('UITEST-not-a-password');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/auth/login')),
      page.locator('button[type=submit]').first().click(),
    ]);
    await page.waitForTimeout(1500);

    expect(page.url(), 'після невдалого входу лишаємось на сторінці входу').toContain('/login');
    const text = await pageText(page);
    expect(text, 'має бути повідомлення про невірні дані').toMatch(/Невірний e-mail або пароль|Невірні/i);
  });

  test('M-01.3 X-09 прямий перехід без токена веде на сторінку входу', async ({ page }) => {
    for (const path of ['/today', '/week']) {
      await page.goto(HOSTS.store + path);
      await page.waitForTimeout(1500);
      expect(page.url(), `${path} без токена має вести на /login`).toContain('/login');
    }
  });

  test('M-01.4 співробітник із правами магазину потрапляє на дошку', async ({ page }) => {
    // Ґрунт із API: які саме права видав бекенд цьому обліковому запису.
    const login = await storeLogin(ctx);
    const storeRights = login.user.permissions.filter((p) => p.startsWith('booking.') || p.startsWith('slot.'));
    expect(
      storeRights,
      'бекенд має видати обліковому запису права на дії магазину',
    ).toContain('booking.mark_arrived');

    await openBoard(page);
    expect(await pageText(page), 'заголовок дошки').toContain('Прибуття');
  });

  test('M-01.5 X-01 перемикач магазину містить усі доступні магазини', async ({ page }) => {
    const login = await storeLogin(ctx);
    // networkWide = доступ до всієї мережі; інакше — рівно перелік scope.storeIds.
    const expected = login.user.scope.networkWide
      ? (await ctx
          .get(`${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`, {
            headers: { Authorization: `Bearer ${supplierToken}` },
          })
          .then((r) => r.json())
          .then((b) => (b.items as SandboxStore[]).map((s) => s.externalId)))
      : login.user.scope.storeIds;
    expect(expected.length, 'для перевірки потрібен хоча б один доступний магазин').toBeGreaterThan(0);

    await openBoard(page);

    const selectCount = await page.locator('.picker__trigger').count();
    expect(
      selectCount,
      `користувач має доступ до ${expected.length} магазинів — потрібен перемикач`,
    ).toBe(1);

    const shown = await pickerOptions(page);
    expect(
      shown.length,
      `у перемикачі ${shown.length} магазинів, а доступно ${expected.length}`,
    ).toBeGreaterThanOrEqual(expected.length);
  });

  test('M-01.6 перемикання магазину змінює дошку і зберігається після перезавантаження', async ({ page }) => {
    await openBoard(page);
    const labels = await pickerOptions(page);
    expect(labels.length, 'для перемикання потрібно 2+ магазини').toBeGreaterThan(1);

    // Беремо іншу філію, ніж та, що стоїть зараз.
    const current = await pickerLabel(page);
    const target = labels.find((l) => l !== current);
    expect(target, 'потрібна інша філія, ніж поточна').toBeTruthy();

    await page.locator('.picker__trigger').click();
    await withBoardResponse(
      page,
      (url) => url.includes('storeId='),
      async () => {
        await page.locator('.picker__option', { hasText: target! }).first().click();
      },
    );
    const chosen = await pickerLabel(page);
    expect(chosen, 'перемикач має показувати щойно обрану філію').toBe(target);

    await reloadBoard(page);
    expect(
      await pickerLabel(page),
      'вибір магазину має зберігатися між перезавантаженнями',
    ).toBe(chosen);
  });
});

// ===========================================================================
// M-02. Дошка «Сьогодні»
// ===========================================================================

test.describe('M-02 Дошка «Сьогодні»', () => {
  test('M-02.0 бекенд надає читання дошки магазину', async () => {
    const login = await storeLogin(ctx);
    const store = stores[0];
    const res = await ctx.get(
      `${HOSTS.store}/api/store/v1/bookings?storeId=${store.storeId}&date=${kyivDateKey()}`,
      { headers: { Authorization: `Bearer ${login.accessToken}` } },
    );
    expect(
      res.status(),
      'без маршруту читання бронювань дошка магазину не може працювати в принципі; ' +
        `отримано ${res.status()}: ${(await res.text()).slice(0, 180)}`,
    ).toBe(200);
  });

  test('M-02.1 дошка за рампами показує бронювання з повним складом картки', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('board');

    await openBoard(page, booking.store);
    const card = cardByPlate(page, booking.vehicle.plateNumber);
    await expect(card, `картка бронювання ${booking.orderId} має бути на дошці`).toBeVisible();

    const text = (await card.innerText()).replace(/\s+/g, ' ');
    // Склад картки: час слоту, статус, постачальник, номер авто, тоннаж,
    // номер замовлення, палети, водій.
    expect(text, 'час слоту').toContain(kyivTime(booking.slotStart));
    expect(text, 'статус').toContain('Заплановано');
    expect(text, 'назва постачальника').toContain(booking.supplierName);
    expect(text, 'номер авто').toContain(booking.vehicle.plateNumber);
    expect(text, 'тоннаж').toContain(String(booking.vehicle.weightTons));
    expect(text, 'номер замовлення').toContain(booking.orderId as string);
    expect(text, 'кількість палет').toContain(String(booking.palletsCount));
    expect(text, 'відмітка про водія').toMatch(/Водія не призначено|Водій /);

    // Картка стоїть у колонці своєї рампи.
    const rampName = booking.store.ramps.find((r) => r.rampId === booking.rampId)?.name;
    const column = page.locator('.board__col').filter({ hasText: booking.vehicle.plateNumber }).first();
    await expect(column.locator('.board__colhead'), 'колонка рампи').toContainText(rampName as string);
  });

  test('M-02.2 таймлайн показує те саме бронювання', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('timeline');

    await openBoard(page, booking.store);
    await page.getByRole('button', { name: 'Таймлайн', exact: true }).click();
    await page.waitForTimeout(500);

    await expect(page.locator('.timeline'), 'режим таймлайну має відкритися').toBeVisible();

    // Підпис чипа — назва постачальника, і в усіх бронювань філії вона та сама,
    // тому своє бронювання шукаємо за підказкою: час, постачальник, номер авто.
    const own = page.locator(
      `.timeline__item[title*="${booking.vehicle.plateNumber}"]`,
    );
    await expect(own, 'бронювання має бути на таймлайні').toBeVisible();
    await expect(own, 'підказка чипа має називати бронювання').toHaveAttribute(
      'title',
      new RegExp(
        `${kyivTime(booking.slotStart)}.*${booking.vehicle.plateNumber}`,
      ),
    );
    await expect(
      page.locator('.timeline__row').filter({ has: own }),
      'рядок таймлайну підписаний рампою',
    ).toContainText(booking.store.ramps.find((r) => r.rampId === booking.rampId)?.name as string);
  });

  test('M-02.3 X-04 день без бронювань показує свідоме повідомлення', async ({ page }) => {
    await openBoard(page, primaryStore());
    // Дата в межах горизонту, на яку бронювань свідомо немає.
    await setBoardDate(page, kyivDateKey(13));
    const text = await pageText(page);
    expect(text, 'порожній день має пояснюватися текстом').toContain('На цю дату немає бронювань');
  });

  test('M-02.4 автооновлення дошки: позначка часу оновлення', async ({ page }) => {
    await openBoard(page);
    const text = await pageText(page);
    expect(
      text,
      'дошка має показувати час останнього оновлення (або банер неактуальності)',
    ).toMatch(/Оновлено о \d{2}:\d{2}|Дані можуть бути неактуальні/);
  });
});

// ===========================================================================
// M-03. Дії магазину
// ===========================================================================

test.describe('M-03 Дії магазину', () => {
  /** Спільний хід: знайти картку, натиснути дію, перевірити стан ПІСЛЯ перезавантаження. */
  async function expectStatusAfterReload(page: Page, plate: string, status: string): Promise<void> {
    await reloadBoard(page);
    const card = cardByPlate(page, plate);
    await expect(
      card,
      `після перезавантаження картка ${plate} має лишитися на дошці`,
    ).toBeVisible();
    await expect(card.locator('.badge').first(), `статус після перезавантаження`).toContainText(status);
  }

  test('M-03.1 прибуття: booked → «Очікує на території»', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('arrive', { store: arrivalStore });
    await openBoard(page, booking.store);

    const card = cardByPlate(page, booking.vehicle.plateNumber);
    await card.getByRole('button', { name: 'На місці' }).click();
    await page.waitForTimeout(1500);

    await expectStatusAfterReload(page, booking.vehicle.plateNumber, 'Очікує на території');
    expect(
      (await readBooking(ctx, supplierToken, booking.id)).status,
      'бекенд теж має бачити новий статус',
    ).toBe('arrived');
  });

  test('M-03.2 початок розвантаження: arrived → «Розвантаження»', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('unload', { store: arrivalStore });
    await openBoard(page, booking.store);

    const card = cardByPlate(page, booking.vehicle.plateNumber);
    await card.getByRole('button', { name: 'На місці' }).click();
    await page.waitForTimeout(1200);
    await cardByPlate(page, booking.vehicle.plateNumber)
      .getByRole('button', { name: 'Розвантаження почалось' })
      .click();
    await page.waitForTimeout(1200);

    await expectStatusAfterReload(page, booking.vehicle.plateNumber, 'Розвантаження');
    expect((await readBooking(ctx, supplierToken, booking.id)).status).toBe('unloading');
  });

  test('M-03.3 завершення з фактичною кількістю палет', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('complete', { palletsCount: 12, store: arrivalStore });
    await openBoard(page, booking.store);

    const plate = booking.vehicle.plateNumber;
    await cardByPlate(page, plate).getByRole('button', { name: 'На місці' }).click();
    await page.waitForTimeout(1200);
    await cardByPlate(page, plate).getByRole('button', { name: 'Розвантаження почалось' }).click();
    await page.waitForTimeout(1200);
    await cardByPlate(page, plate).getByRole('button', { name: 'Розвантажено', exact: true }).click();

    const dialog = page.locator('[role=dialog]');
    await expect(dialog, 'має відкритися вікно підтвердження розвантаження').toBeVisible();
    await expect(dialog, 'у вікні видно заявлену кількість палет').toContainText('Заявлено палет: 12');
    await dialog.locator('#unloaded').fill('12');
    await dialog.getByRole('button', { name: 'Розвантажено' }).click();
    await page.waitForTimeout(1500);

    await expectStatusAfterReload(page, plate, 'Розвантажено');
    const after = await readBooking(ctx, supplierToken, booking.id);
    expect(after.status).toBe('completed');
    expect(
      (after as unknown as { unloadedPalletsCount: number }).unloadedPalletsCount,
      'фактична кількість палет має зберегтися',
    ).toBe(12);
  });

  test('M-03.4 часткове розвантаження вимагає причину з довідника', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('partial', { palletsCount: 10, store: arrivalStore });
    await openBoard(page, booking.store);

    const plate = booking.vehicle.plateNumber;
    await cardByPlate(page, plate).getByRole('button', { name: 'На місці' }).click();
    await page.waitForTimeout(1200);
    await cardByPlate(page, plate).getByRole('button', { name: 'Розвантаження почалось' }).click();
    await page.waitForTimeout(1200);
    await cardByPlate(page, plate).getByRole('button', { name: 'Розвантажено', exact: true }).click();

    const dialog = page.locator('[role=dialog]');
    await dialog.locator('#unloaded').fill('4');
    await dialog.locator('#unloaded').dispatchEvent('input');
    await page.waitForTimeout(300);

    // X-05: без причини форма не має проходити.
    await dialog.getByRole('button', { name: 'Розвантажено' }).click();
    await expect(dialog.locator('.form-error'), 'має вимагатися причина').toContainText(
      'Оберіть причину часткового розвантаження',
    );

    // Довідник причин має збігатися з переліком бекенду.
    expect(await optionTexts(page, '#partial-reason'), 'довідник причин часткового розвантаження')
      .toEqual([...PARTIAL_UNLOAD_REASONS]);

    await dialog.locator('#partial-reason').selectOption({ label: 'немає місця' });
    await dialog.getByRole('button', { name: 'Розвантажено' }).click();
    await page.waitForTimeout(1500);

    await expectStatusAfterReload(page, plate, 'Розвантажено');
    const after = (await readBooking(ctx, supplierToken, booking.id)) as unknown as {
      status: string;
      unloadedPalletsCount: number;
      partialUnload: { reason: string } | null;
    };
    expect(after.unloadedPalletsCount).toBe(4);
    expect(after.partialUnload?.reason, 'причина часткового розвантаження').toBe('немає місця');
  });

  test('M-03.5 відмова в прийомі з причиною з довідника', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('reject', { store: arrivalStore });
    await openBoard(page, booking.store);

    const plate = booking.vehicle.plateNumber;
    await cardByPlate(page, plate).getByRole('button', { name: 'На місці' }).click();
    await page.waitForTimeout(1200);
    await cardByPlate(page, plate).getByRole('button', { name: 'Відмовити в прийомі' }).click();

    const dialog = page.locator('[role=dialog]');
    await expect(dialog).toBeVisible();
    expect(await optionTexts(page, '#reject-reason'), 'довідник причин відмови').toEqual([
      ...REJECT_REASONS,
    ]);

    // X-05: без причини — відмова з поясненням.
    await dialog.getByRole('button', { name: 'Відмовити в прийомі' }).click();
    await expect(dialog.locator('.form-error')).toContainText('Вкажіть причину відмови з довідника');

    await dialog.locator('#reject-reason').selectOption({ label: 'відсутні документи' });
    await dialog.getByRole('button', { name: 'Відмовити в прийомі' }).click();
    await page.waitForTimeout(1500);

    await expectStatusAfterReload(page, plate, 'Відмовлено в прийомі');
    expect((await readBooking(ctx, supplierToken, booking.id)).status).toBe('rejected');
  });

  test('M-03.6 «не приїхав» доступний лише після закінчення слоту', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('noshow');
    await openBoard(page, booking.store);

    const card = cardByPlate(page, booking.vehicle.plateNumber);
    const button = card.getByRole('button', { name: 'Не приїхав' });
    await expect(button, 'кнопка «Не приїхав» має бути на картці').toBeVisible();

    const slotOver = Date.now() > new Date(booking.slotEnd).getTime();
    if (slotOver) {
      await button.click();
      const dialog = page.locator('[role=dialog]');
      await expect(dialog, 'дія має вимагати підтвердження').toBeVisible();
      await expect(dialog).toContainText('Дію неможливо скасувати');
      await dialog.getByRole('button', { name: 'Підтвердити' }).click();
      await page.waitForTimeout(1500);
      await expectStatusAfterReload(page, booking.vehicle.plateNumber, 'Не приїхав');
      expect((await readBooking(ctx, supplierToken, booking.id)).status).toBe('no_show');
    } else {
      await expect(button, 'до кінця слоту дія має бути недоступною').toBeDisabled();
      expect(
        await button.getAttribute('title'),
        'недоступність має пояснюватися підказкою',
      ).toContain('після закінчення слоту');
    }
  });

  test('M-03.7 затримка з причиною і новим часом', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('delay');
    await openBoard(page, booking.store);

    const plate = booking.vehicle.plateNumber;
    await cardByPlate(page, plate).getByRole('button', { name: 'Повідомити про затримку' }).click();

    const dialog = page.locator('[role=dialog]');
    await expect(dialog).toBeVisible();
    expect(await optionTexts(page, '#delay-reason'), 'довідник причин затримки').toEqual([
      ...DELAY_REASONS,
    ]);

    // X-05: без причини і часу форма не проходить.
    await dialog.getByRole('button', { name: 'Зберегти' }).click();
    const errors = (await dialog.locator('.form-error').allInnerTexts()).join(' ');
    expect(errors, 'мають бути вимоги причини і нового часу').toContain('Оберіть причину затримки');
    expect(errors).toContain('Вкажіть новий орієнтовний час');

    // Новий час — на годину пізніше за початок слоту, у межах тієї самої доби.
    const eta = new Date(new Date(booking.slotStart).getTime() + 60 * 60_000).toISOString();
    await dialog.locator('#delay-reason').selectOption({ label: 'затори' });
    await dialog.locator('#delay-eta').fill(kyivTime(eta));
    await dialog.getByRole('button', { name: 'Зберегти' }).click();
    await page.waitForTimeout(1500);

    await reloadBoard(page);
    const card = cardByPlate(page, plate);
    await expect(card, 'на картці має бути позначка затримки').toContainText('Затримка до');
    const after = (await readBooking(ctx, supplierToken, booking.id)) as unknown as {
      delayed: { flag: boolean; reason: string | null };
    };
    expect(after.delayed.flag, 'бекенд має зберегти прапорець затримки').toBe(true);
    expect(after.delayed.reason).toBe('затори');
  });

  test('M-03.8 переведення на іншу рампу', async ({ page }) => {
    test.setTimeout(120_000);
    const target = arrivalStore;
    expect(
      target.ramps.filter((r) => r.active !== false).length,
      'для переведення потрібна філія з 2+ рампами',
    ).toBeGreaterThan(1);

    // Слот обирається свідомо: переводити є куди лише тоді, коли в ТОЙ САМИЙ
    // час вільна ще одна рампа. Без цього бронювання лягало на «перший вільний»
    // слот, попередні перевірки встигали зайняти сусідні рампи того ж часу,
    // і кнопка переведення чесно вимикалася — тест падав не на дефекті.
    const grid = await slotGrid(ctx, supplierToken, target.storeId, kyivDateKey());
    const freePerStart = new Map<string, number>();
    for (const slot of grid.slots) {
      if (slot.state === 'available' && slot.selectable) {
        freePerStart.set(slot.slotStart, (freePerStart.get(slot.slotStart) ?? 0) + 1);
      }
    }
    const pairSlot = [...freePerStart.entries()]
      .filter(([, count]) => count >= 2)
      .map(([slotStart]) => slotStart)
      .sort()[0];
    expect(
      pairSlot,
      'для переведення потрібен час, у який на філії вільні щонайменше дві рампи',
    ).toBeTruthy();

    const booking = await createBooking(ctx, supplierToken, [target], {
      label: 'reassign',
      slotStart: pairSlot,
    });
    created.push(booking.id);

    await openBoard(page, target);
    const plate = booking.vehicle.plateNumber;
    await cardByPlate(page, plate).getByRole('button', { name: 'Перевести на іншу рампу' }).click();

    const dialog = page.locator('[role=dialog]');
    await expect(dialog).toBeVisible();
    const currentName = target.ramps.find((r) => r.rampId === booking.rampId)?.name as string;
    await expect(dialog, 'у вікні видно поточну рампу').toContainText(currentName);

    const options = await optionTexts(page, '#target-ramp');
    expect(options.length, 'має бути хоча б одна вільна рампа для переведення').toBeGreaterThan(0);
    const newRampName = options[0];
    await dialog.locator('#target-ramp').selectOption({ label: newRampName });
    await dialog.getByRole('button', { name: 'Підтвердити' }).click();
    await page.waitForTimeout(1500);

    await reloadBoard(page);
    const column = page.locator('.board__col').filter({ hasText: plate }).first();
    await expect(
      column.locator('.board__colhead'),
      'після перезавантаження картка має стояти в колонці нової рампи',
    ).toContainText(newRampName);

    const after = await readBooking(ctx, supplierToken, booking.id);
    const newRampId = target.ramps.find((r) => r.name === newRampName)?.rampId;
    expect(after.rampId, 'бекенд має бачити нову рампу').toBe(newRampId);
  });
});

// ===========================================================================
// M-04. Walk-in (позапланове прибуття)
// ===========================================================================

test.describe('M-04 Позапланове прибуття', () => {
  /** Скільки постачальників справді є в системі (ґрунт для перевірки повноти). */
  async function allSuppliers(): Promise<{ id: string; name: string }[]> {
    const login = await ctx.post(`${HOSTS.admin}/api/admin/v1/auth/login`, { data: CREDS.admin });
    const token = (await login.json()).accessToken as string;
    // Еталон теж треба збирати посторінково: у partner-service ліміт сторінки
    // свій, і одна сторінка вже не вміщає всіх постачальників стенду.
    const items: { id: string; name: string; status?: string }[] = [];
    let total = 0;
    for (let offset = 0; ; offset += 100) {
      const page = await ctx.get(`${HOSTS.admin}/api/admin/v1/suppliers?limit=100&offset=${offset}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await page.json();
      const chunk = (body.items ?? []) as { id: string; name: string; status?: string }[];
      total = body.total ?? chunk.length;
      items.push(...chunk);
      if (chunk.length === 0 || items.length >= total) break;
    }
    expect(
      items.length,
      `зібрано ${items.length} із ${total} — довідник для звірки неповний`,
    ).toBe(total);
    return items.filter((s) => s.status !== 'archived');
  }

  test('M-04.1 X-01 список постачальників у формі повний', async ({ page }) => {
    const expected = await allSuppliers();
    expect(expected.length, 'для перевірки потрібен хоч один постачальник').toBeGreaterThan(0);

    await openBoard(page, primaryStore());
    await openWalkIn(page);

    const shown = await optionTexts(page, '#wi-supplier');
    const missing = expected.filter((s) => !shown.some((t) => t.includes(s.name)));
    expect(
      missing.map((s) => s.name),
      `у списку ${shown.length} постачальників, а в системі ${expected.length}; немає: ${missing
        .map((s) => s.name)
        .join(', ')}`,
    ).toHaveLength(0);
  });

  test('M-04.2 X-05 валідація полів форми', async ({ page }) => {
    const store = primaryStore();
    await openBoard(page, store);
    const dialog = await openWalkIn(page);

    await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
    await expect(
      dialog.locator('.form-error').first(),
      'порожня форма має пояснити, чого саме бракує',
    ).toBeVisible();
    const errors = (await dialog.locator('.form-error').allInnerTexts()).join(' | ');
    expect(errors, 'постачальник').toContain('Оберіть постачальника або вкажіть назву');
    expect(errors, 'номер авто').toContain('Вкажіть номер авто');
    expect(errors, 'тоннаж').toContain('Вкажіть тоннаж авто');
    expect(errors, 'палети').toContain('Кількість палет — від 1 до 33');
    expect(errors, 'слот').toContain('Оберіть вільний слот');

    // Межові значення: 0 і 34 палети мають відхилятися.
    await dialog.locator('#wi-pallets').fill('34');
    await dialog.locator('#wi-pallets').dispatchEvent('input');
    await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
    expect(
      (await dialog.locator('.form-error').allInnerTexts()).join(' '),
      '34 палети мають відхилятися',
    ).toContain('Кількість палет — від 1 до 33');

    // Тоннаж понад ліміт філії має відхилятися з окремим текстом. Ліміт — саме
    // тієї філії, яку показує дошка: форма перевіряє її, а не довільну філію
    // пісочниці (у 2227 ліміт 10 т, у 2229 — 40, і «10 + 5» тут ні про що).
    const limit = store.maxVehicleWeightTons;
    await dialog.locator('#wi-weight').fill(String(limit + 5));
    await dialog.locator('#wi-weight').dispatchEvent('input');
    await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
    expect(
      (await dialog.locator('.form-error').allInnerTexts()).join(' '),
      'тоннаж понад ліміт філії',
    ).toContain('Тоннаж авто перевищує допустимий');
  });

  test('M-04.3 реєстрація постачальника зі списку → статус «на місці»', async ({ page }) => {
    test.setTimeout(120_000);
    await openBoard(page, arrivalStore);
    const dialog = await openWalkIn(page);

    const suppliers = await optionTexts(page, '#wi-supplier');
    expect(suppliers.length, 'у формі має бути з чого вибрати').toBeGreaterThan(0);
    const slots = await optionTexts(page, '#wi-slot');
    expect(slots.length, 'мають бути вільні слоти на сьогодні').toBeGreaterThan(0);

    const plate = testPlate();
    await dialog.locator('#wi-supplier').selectOption({ label: suppliers[0] });
    await dialog.locator('#wi-plate').fill(plate);
    await dialog.locator('#wi-weight').fill('8');
    await dialog.locator('#wi-weight').dispatchEvent('input');
    await dialog.locator('#wi-pallets').fill('6');
    await dialog.locator('#wi-pallets').dispatchEvent('input');
    await dialog.locator('#wi-order').fill('UITEST-walkin');
    await dialog.locator('#wi-slot').selectOption({ label: slots[0] });

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/bookings/walk-in')),
      dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click(),
    ]);
    expect(response.status(), 'реєстрація має завершитися створенням').toBe(201);
    const body = (await response.json()) as Booking;
    created.push(body.id);
    registerArtifact('walk-in', body.id, `${plate} · UITEST-walkin`);

    await reloadBoard(page);
    const card = cardByPlate(page, plate);
    await expect(card, 'позапланове прибуття має зʼявитися на дошці').toBeVisible();
    await expect(card.locator('.badge').first(), 'статус «на місці»').toContainText(
      'Очікує на території',
    );
    await expect(card, 'картка має бути позначена як позапланова').toContainText('Позапланове');
  });

  test('M-04.4 реєстрація постачальника «поза системою»', async ({ page }) => {
    test.setTimeout(120_000);
    await openBoard(page, arrivalStore);
    const dialog = await openWalkIn(page);

    await dialog.getByRole('button', { name: 'Поза системою' }).click();
    await expect(dialog.locator('#wi-external'), 'має зʼявитися поле назви').toBeVisible();

    const plate = testPlate();
    const name = 'UITEST-Поза системою';
    await dialog.locator('#wi-external').fill(name);
    await dialog.locator('#wi-plate').fill(plate);
    await dialog.locator('#wi-weight').fill('7');
    await dialog.locator('#wi-weight').dispatchEvent('input');
    await dialog.locator('#wi-pallets').fill('3');
    await dialog.locator('#wi-pallets').dispatchEvent('input');
    const slots = await optionTexts(page, '#wi-slot');
    await dialog.locator('#wi-slot').selectOption({ label: slots[0] });

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/bookings/walk-in')),
      dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click(),
    ]);
    expect(response.status()).toBe(201);
    const body = (await response.json()) as Booking;
    created.push(body.id);
    registerArtifact('walk-in', body.id, `${plate} · ${name}`);

    await reloadBoard(page);
    const card = cardByPlate(page, plate);
    await expect(card, 'назва постачальника поза системою має бути на картці').toContainText(name);
    await expect(card.locator('.badge').first()).toContainText('Очікує на території');
  });
});

// ===========================================================================
// M-05. Фільтри і статистика
// ===========================================================================

test.describe('M-05 Фільтри і статистика', () => {
  test('M-05.1 фільтр за рампою залишає лише свою рампу', async ({ page }) => {
    test.setTimeout(120_000);
    // Цілодобова філія: фільтр за рампою перевіряється в будь-яку годину доби.
    const multiRamp = arrivalStore;
    expect(
      multiRamp.ramps.filter((r) => r.active !== false).length,
      'для перевірки потрібна філія з 2+ рампами',
    ).toBeGreaterThan(1);

    const first = await createBooking(ctx, supplierToken, [multiRamp], {
      label: 'filter-a',
      rampId: multiRamp.ramps[0].rampId,
    });
    created.push(first.id);
    const second = await createBooking(ctx, supplierToken, [multiRamp], {
      label: 'filter-b',
      rampId: multiRamp.ramps[1].rampId,
    });
    created.push(second.id);

    await openBoard(page, multiRamp);
    const rampName = multiRamp.ramps[0].name;
    await page.locator('.filters__chips .chip').filter({ hasText: rampName }).first().click();
    await page.waitForTimeout(500);

    await expect(cardByPlate(page, first.vehicle.plateNumber)).toBeVisible();
    expect(
      await page.locator('article.bcard').filter({ hasText: second.vehicle.plateNumber }).count(),
      'бронювання іншої рампи має зникнути',
    ).toBe(0);

    await page.getByRole('button', { name: 'Очистити' }).click();
    await page.waitForTimeout(500);
    await expect(cardByPlate(page, second.vehicle.plateNumber), 'скидання фільтра').toBeVisible();
  });

  test('M-05.2 фільтр за постачальником знаходить усіх, хто є на дошці', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('supfilter');
    await openBoard(page, booking.store);

    // Ґрунт: перелік постачальників, чиї бронювання є на дошці.
    const namesOnBoard = await page.locator('.bcard__supplier').allInnerTexts();
    const unique = [...new Set(namesOnBoard.map((n) => n.trim()))];
    expect(unique.length, 'на дошці має бути хоч один постачальник').toBeGreaterThan(0);

    for (const name of unique) {
      await page.locator('#supplier-query').fill(name);
      await page.waitForTimeout(600);
      const shown = await page.locator('.bcard__supplier').allInnerTexts();
      expect(
        shown.map((s) => s.trim()),
        `пошук «${name}» має знаходити його бронювання`,
      ).toContain(name);
    }

    await page.locator('#supplier-query').fill(booking.supplierName.slice(0, 5));
    await page.waitForTimeout(600);
    await expect(
      cardByPlate(page, booking.vehicle.plateNumber),
      'пошук за фрагментом назви теж має працювати',
    ).toBeVisible();
  });

  test('M-05.3 фільтр за статусом', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('statusfilter');
    await openBoard(page, booking.store);

    await page.locator('.filters__chips .chip').filter({ hasText: 'Заплановано' }).first().click();
    await page.waitForTimeout(500);
    await expect(cardByPlate(page, booking.vehicle.plateNumber)).toBeVisible();

    const statuses = await page.locator('article.bcard .badge').allInnerTexts();
    const foreign = statuses
      .map((s) => s.trim())
      .filter((s) => ['Розвантаження', 'Розвантажено', 'Не приїхав', 'Відмовлено в прийомі'].includes(s));
    expect(foreign, 'фільтр «Заплановано» не має лишати інші статуси').toHaveLength(0);
  });

  test('M-05.4 денна статистика збігається з фактичними даними', async ({ page }) => {
    test.setTimeout(120_000);
    const store = primaryStore();
    await makeBooking('stats', { store });
    await openBoard(page, store);

    // Скільки бронювань справді зайняли слоти цієї філії на цю дату (API).
    const grid = await slotGrid(ctx, supplierToken, store.storeId, kyivDateKey());
    const occupied = grid.slots.filter((s) => s.state === 'booked').length;

    const total = await statValue(page, 'Всього');
    const cards = await page.locator('.board__col article.bcard').count();

    expect(total, `«Всього» має збігатися з кількістю карток на дошці`).toBe(cards);
    expect(
      total,
      `«Всього» ${total} менше за кількість зайнятих слотів у сітці ${occupied} — дошка щось не показує`,
    ).toBeGreaterThanOrEqual(occupied);

    // Кожен лічильник має дорівнювати кількості карток відповідного статусу.
    const badges = (await page.locator('.board__col article.bcard .badge').allInnerTexts()).map((t) =>
      t.trim(),
    );
    const countOf = (label: string) => badges.filter((b) => b === label).length;
    expect(await statValue(page, 'Розвантажено'), 'лічильник «Розвантажено»').toBe(
      countOf('Розвантажено'),
    );
    expect(await statValue(page, 'Не приїхали'), 'лічильник «Не приїхали»').toBe(
      countOf('Не приїхав'),
    );
    expect(await statValue(page, 'Відмовлено'), 'лічильник «Відмовлено»').toBe(
      countOf('Відмовлено в прийомі'),
    );
  });
});

// ===========================================================================
// M-06. Інші дати і тижневий розклад
// ===========================================================================

test.describe('M-06 Інші дати і тиждень', () => {
  test('M-06.1 перегляд завтрашнього дня показує завтрашні бронювання', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('tomorrow', { dateKey: kyivDateKey(1) });

    await openBoard(page, booking.store);
    await goToDate(page, 'Завтра', kyivDateKey(1));
    await expect(
      cardByPlate(page, booking.vehicle.plateNumber),
      'завтрашнє бронювання має бути видно на завтрашній дошці',
    ).toBeVisible();

    await goToDate(page, 'Сьогодні', kyivDateKey());
    expect(
      await page.locator('article.bcard').filter({ hasText: booking.vehicle.plateNumber }).count(),
      'на сьогоднішній дошці завтрашнього бронювання бути не має',
    ).toBe(0);
  });

  test('M-06.2 минула дата — лише перегляд', async ({ page }) => {
    await openBoard(page);
    await goToDate(page, 'Вчора', kyivDateKey(-1));
    expect(
      await pageText(page),
      'на минулій даті має бути попередження про режим лише перегляду',
    ).toContain('Минула дата');
  });

  test('M-06.3 тижневий розклад тільки для читання', async ({ page }) => {
    test.setTimeout(120_000);
    await openBoard(page);
    await Promise.all([
      page.waitForResponse((r) => /\/slots\?.*from=/.test(r.url()), { timeout: 30_000 }),
      page.getByRole('link', { name: 'Розклад тижня' }).click(),
    ]);
    await waitForScreen(page);

    const text = await pageText(page);
    expect(text, 'заголовок тижня').toContain('Розклад тижня');
    expect(text, 'позначка режиму перегляду').toContain('Тільки перегляд');
    expect(await page.locator('.week__day').count(), 'у тижні має бути 7 днів').toBe(7);
    expect(
      await page.locator('.legend__item').count(),
      'легенда станів слотів',
    ).toBeGreaterThanOrEqual(5);

    // Жодних дій над бронюваннями на тижневому екрані бути не повинно.
    for (const label of ['На місці', 'Розвантаження почалось', 'Відмовити в прийомі']) {
      expect(
        await page.getByRole('button', { name: label }).count(),
        `на тижневому розкладі не має бути дії «${label}»`,
      ).toBe(0);
    }

    await Promise.all([
      page.waitForResponse((r) => /\/slots\?.*from=/.test(r.url()), { timeout: 30_000 }),
      page.getByRole('button', { name: 'Наступний тиждень' }).click(),
    ]);
    await waitForScreen(page);
    expect(await page.locator('.week__day').count(), 'наступний тиждень теж має будуватися').toBe(7);
  });
});

// ===========================================================================
// M-07. Журнал дій
// ===========================================================================

test.describe('M-07 Журнал дій', () => {
  test('M-07.1 журнал містить щойно виконані переходи з автором і часом', async ({ page }) => {
    test.setTimeout(120_000);
    const booking = await makeBooking('audit', { store: arrivalStore });
    const login = await storeLogin(ctx);

    await openBoard(page, booking.store);
    const plate = booking.vehicle.plateNumber;
    await cardByPlate(page, plate).getByRole('button', { name: 'На місці' }).click();
    await page.waitForTimeout(1500);

    await cardByPlate(page, plate).getByRole('button', { name: 'Журнал дій' }).click();
    const dialog = page.locator('[role=dialog]');
    await expect(dialog).toBeVisible();

    const text = (await dialog.innerText()).replace(/\s+/g, ' ');
    expect(text, 'журнал має містити перехід у «Очікує на території»').toContain(
      'Очікує на території',
    );
    expect(text, 'журнал має містити створення бронювання («Заплановано»)').toContain('Заплановано');
    expect(text, 'у журналі має бути автор дії').toContain(login.user.id);
    expect(text, 'у журналі має бути час дії').toMatch(/\d{2}:\d{2}/);
    // Журнал має називати виконавця людині зрозуміло, а не лише ідентифікатором.
    expect(text, 'журнал показує лише ID користувача замість імені виконавця').toContain(
      login.user.fullName,
    );
  });
});

// ===========================================================================
// M-08. Обмеження прав
// ===========================================================================

test.describe('M-08 Обмеження прав', () => {
  test('M-08.1 магазин не створює планових бронювань', async ({ page }) => {
    // Через API контуру магазину такого маршруту не має існувати.
    const login = await storeLogin(ctx);
    const res = await ctx.post(`${HOSTS.store}/api/store/v1/bookings`, {
      headers: { Authorization: `Bearer ${login.accessToken}` },
      data: {
        storeId: stores[0].storeId,
        rampId: stores[0].ramps[0].rampId,
        slotStart: `${kyivDateKey(1)}T09:00:00Z`,
        palletsCount: 5,
        vehicle: { plateNumber: 'UT0000XX', weightTons: 5 },
      },
    });
    expect(
      [404, 405],
      `створення планового бронювання з контуру магазину має бути неможливим, отримано ${res.status()}`,
    ).toContain(res.status());

    // І в інтерфейсі не має бути такої дії.
    await openBoard(page);
    const text = await pageText(page);
    expect(text, 'на дошці не має бути створення планового бронювання').not.toMatch(
      /Створити бронювання|Нове бронювання|Забронювати слот/,
    );
    expect(
      await page.getByRole('button', { name: 'Позапланове прибуття' }).count(),
      'доступна має бути лише реєстрація позапланового прибуття',
    ).toBe(1);
  });

  test('M-08.2 токен магазину не працює в контурі постачальника', async () => {
    const login = await storeLogin(ctx);
    for (const path of [
      '/api/supplier/v1/route-sheets?date=' + kyivDateKey(),
      '/api/supplier/v1/vehicles',
      '/api/supplier/v1/drivers',
    ]) {
      const res = await ctx.get(`${HOSTS.supplier}${path}`, {
        headers: { Authorization: `Bearer ${login.accessToken}` },
      });
      expect([401, 403], `${path} має бути закритий для токена магазину`).toContain(res.status());
    }
  });

  test('M-08.3 підміна обраного магазину не дає доступу до чужої філії', async ({ page }) => {
    const fake = '00000000-0000-0000-0000-000000000000';
    await openBoard(page, primaryStore());

    // Підміняємо збережений вибір магазину на ідентифікатор поза скоупом.
    await page.evaluate((id) => {
      localStorage.setItem('yms.store.selectedStoreId', id);
    }, fake);
    await reloadBoard(page);

    const text = await pageText(page);
    expect(text, 'підмінений ідентифікатор не має потрапляти в інтерфейс').not.toContain(fake);
    // Застосунок має відкотитися на дозволену філію, а не працювати з чужою.
    const restored = await page.evaluate(() => localStorage.getItem('yms.store.selectedStoreId'));
    expect(restored, 'вибір має повернутися до магазину зі скоупу користувача').not.toBe(fake);

    // Прямий запит до бронювань чужої філії теж має бути закритий.
    const login = await storeLogin(ctx);
    const res = await ctx.get(`${HOSTS.store}/api/store/v1/bookings?storeId=${fake}&date=${kyivDateKey()}`, {
      headers: { Authorization: `Bearer ${login.accessToken}` },
    });
    expect(
      [403, 404],
      `бронювання неіснуючої філії не мають віддаватися, отримано ${res.status()}`,
    ).toContain(res.status());
  });
});

// ===========================================================================
// Наскрізні перевірки на екранах магазину
// ===========================================================================

test.describe('Наскрізні перевірки модуля магазину', () => {
  test('X-07 в інтерфейсі немає неперекладених ключів', async ({ page }) => {
    await openBoard(page, primaryStore());
    const text = await pageText(page);

    // Префікси словника store-web: якщо такий ключ видно текстом — переклад не спрацював.
    const keyLike =
      text.match(
        /\b(app|common|access|header|board|card|status|action|complete|noShow|reject|delay|reassign|walkIn|filters|stats|realtime|week|slotState|log|error|login)\.[A-Za-z][A-Za-z0-9.]*/g,
      ) ?? [];
    const suspicious = [...new Set(keyLike)];
    expect(suspicious, `на екрані видно ключі перекладу: ${suspicious.join(', ')}`).toHaveLength(0);
  });

  test('X-10 сторінка входу без горизонтального скролу на 360/768/1280', async ({ page }) => {
    for (const width of [360, 768, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto(HOSTS.store + '/login');
      await page.waitForSelector('input[type=password]');
      expect(
        await hasHorizontalScroll(page),
        `на ширині ${width}px зʼявився горизонтальний скрол`,
      ).toBe(false);
    }
  });

  test('X-10 дошка без горизонтального скролу на 360/768/1280', async ({ page }) => {
    test.setTimeout(120_000);
    await openBoard(page);
    for (const width of [360, 768, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      await page.waitForTimeout(600);
      expect(
        await hasHorizontalScroll(page),
        `дошка «Сьогодні» на ширині ${width}px має горизонтальний скрол`,
      ).toBe(false);
    }
  });
});
