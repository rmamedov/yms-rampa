/**
 * S-02 Головна, S-03 Вибір міста, S-04 Список філій, S-05 Сітка слотів.
 *
 * Кожне очікуване число береться з API стенду: скільки міст, скільки філій у
 * місті, скільки слотів у сітці на цю дату. Порівнюємо з тим, що бачить
 * користувач.
 */
import { expect, test } from '@playwright/test';
import { HOSTS } from '../support/env';
import {
  bodyText,
  cellAt,
  diffDays,
  goto,
  kyivToday,
  languageProblems,
  loginSupplier,
  nearestSunday,
  normalizedText,
  selectGridDate,
  shiftDate,
  stableSet,
  Sup,
  workingDay,
  type RouteSheetPointDto,
  type StoreDto,
} from '../support/supplier';

let api: Sup;
let kharkiv: StoreDto[];

test.beforeAll(async () => {
  api = await Sup.open();
  kharkiv = (await api.stores('Харків')).items;
});

test.afterAll(async () => {
  await api.dispose();
});

test.describe('S-02 Головна', () => {
  test('S-02.1 найближчі поставки збігаються з даними API', async ({ page }) => {
    const today = kyivToday();
    const dates = Array.from({ length: 7 }, (_, i) => shiftDate(today, i));
    const active = new Set(['booked', 'arrived', 'unloading']);

    const read = async (): Promise<RouteSheetPointDto[]> => {
      const out: RouteSheetPointDto[] = [];
      for (const date of dates) {
        const sheet = await api.sheet(date);
        out.push(...sheet.points.filter((p) => active.has(p.status)));
      }
      return out.filter((p) => Date.parse(p.slotStart) >= Date.now());
    };

    const before = await read();
    await loginSupplier(page);
    await goto(page, '/home');
    const cards = page.locator('.delivery');
    await expect(cards.first()).toBeVisible();
    const shown = await bodyText(page);
    const after = await read();

    // На стенді паралельно працюють інші перевірки, тому «мусить бути» — це
    // перетин двох зчитувань API навколо рендера, «може бути» — обʼєднання.
    const { must, may } = stableSet(before, after, (p) => p.bookingId);
    const byTime = (a: RouteSheetPointDto, b: RouteSheetPointDto) => a.slotStart.localeCompare(b.slotStart);
    const firstTen = new Set([...may].sort(byTime).slice(0, 10).map((p) => p.bookingId));

    for (const point of [...must].sort(byTime)) {
      if (!firstTen.has(point.bookingId)) {
        continue; // головна показує лише 10 найближчих
      }
      expect(shown, `поставка ${point.localTime} ${point.plateNumber} має бути на головній`).toContain(
        point.plateNumber,
      );
      expect(shown, `адреса поставки ${point.localTime}`).toContain(point.address);
    }

    const count = await cards.count();
    expect(count, 'карток не менше, ніж стабільно існуючих поставок (максимум 10)').toBeGreaterThanOrEqual(
      Math.min(10, must.length),
    );
    expect(count, 'і не більше, ніж узагалі є активних поставок').toBeLessThanOrEqual(Math.min(10, may.length));
  });

  test('S-02.2 картка веде у маршрутний лист своєї дати', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/home');

    const card = page.locator('.delivery').first();
    await expect(card).toBeVisible();
    const href = await card.locator('a:has-text("Маршрутний лист")').getAttribute('href');
    expect(href, 'посилання має вести на лист конкретної дати').toMatch(/\/route-sheets\/\d{4}-\d{2}-\d{2}/);

    await card.locator('a:has-text("Маршрутний лист")').click();
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(new RegExp(href!.replace(/\//g, '\\/')));
    await expect(page.locator('h1')).toContainText('Маршрутний лист на');

    await goto(page, '/home');
    await page.locator('a:has-text("Усі маршрутні листи")').click();
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/route-sheets$/);
  });

  test('S-02.3 X-07 головна українською, без ключів перекладу', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/home');
    const problems = languageProblems(await bodyText(page));
    expect(problems, `неперекладені фрагменти: ${problems.join(', ')}`).toHaveLength(0);
  });
});

test.describe('S-03 Вибір міста', () => {
  test('S-03.1 X-01 показані всі міста з API і чесні лічильники філій', async ({ page }) => {
    const cities = await api.cities();
    expect(cities.length, 'у постачальника має бути хоч одне місто').toBeGreaterThan(0);

    await loginSupplier(page);
    await goto(page, '/booking/cities');

    const cards = page.locator('.city-grid li');
    await expect(cards.first()).toBeVisible();
    expect(await cards.count(), `в API ${cities.length} міст`).toBe(cities.length);

    const text = await bodyText(page);
    for (const city of cities) {
      expect(text, `місто ${city.city} має бути у списку`).toContain(city.city);

      // Лічильник у картці має збігатися і з /cities, і з фактичним
      // числом філій у /stores — інакше користувач бачить неправду.
      const card = cards.filter({ hasText: city.city }).first();
      const label = normalizedText(await card.innerText());
      expect(label, `картка «${city.city}» має показувати ${city.storeCount}`).toMatch(
        new RegExp(`\\b${city.storeCount}\\b`),
      );

      const stores = await api.stores(city.city);
      expect(stores.total, `лічильник міста ${city.city} має дорівнювати кількості філій`).toBe(city.storeCount);
      expect(stores.items.length, `у відповіді /stores для ${city.city} має бути ${stores.total} записів`).toBe(
        stores.total,
      );
    }
  });

  test('S-03.2 пошук міста кирилицею в різному регістрі', async ({ page }) => {
    const cities = await api.cities();
    const sample = cities.find((c) => c.city === 'Київ') ?? cities[0];

    await loginSupplier(page);
    await goto(page, '/booking/cities');
    const search = page.locator('#city-search');
    const cards = page.locator('.city-grid li');

    for (const query of [sample.city, sample.city.toLowerCase(), sample.city.toUpperCase()]) {
      await search.fill(query);
      await page.waitForTimeout(400);
      const expected = cities.filter((c) => c.city.toLowerCase().includes(query.toLowerCase())).length;
      expect(await cards.count(), `пошук «${query}» має знайти ${expected} міст`).toBe(expected);
      await expect(cards.first()).toContainText(sample.city);
    }

    // Підстрічка в середині назви.
    const middle = sample.city.slice(1, 3);
    await search.fill(middle);
    await page.waitForTimeout(400);
    const expectedMiddle = cities.filter((c) => c.city.toLowerCase().includes(middle.toLowerCase())).length;
    expect(await cards.count(), `пошук «${middle}»`).toBe(expectedMiddle);
  });

  test('S-03.3 X-04 порожній результат пошуку має зрозуміле повідомлення', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/booking/cities');
    await page.locator('#city-search').fill('Такогоміставнемає');
    await page.waitForTimeout(400);

    await expect(page.locator('.empty-state')).toHaveText('Міст не знайдено');
    await expect(page.locator('.city-grid li')).toHaveCount(0);
  });
});

test.describe('S-04 Список філій', () => {
  test('S-04.1 X-01 у КОЖНОМУ місті видно всі активні філії з тоннажем і слотом', async ({ page }) => {
    test.setTimeout(120_000);
    await loginSupplier(page);

    for (const city of await api.cities()) {
      const stores = await api.stores(city.city);
      await goto(page, `/booking/cities/${encodeURIComponent(city.city)}`);

      const cards = page.locator('.branch-grid li');
      await expect(cards.first()).toBeVisible();
      expect(await cards.count(), `в API ${stores.total} філій у місті ${city.city}`).toBe(stores.total);

      const text = await bodyText(page);
      for (const store of stores.items) {
        expect(text, `${city.city}: філія № ${store.externalId} має бути у списку`).toContain(store.externalId);
        expect(text, `${city.city}: адреса ${store.address}`).toContain(store.address);

        const card = cards.filter({ hasText: `Філія № ${store.externalId}` }).first();
        const label = normalizedText(await card.innerText());
        expect(label, `тоннаж філії ${store.externalId}`).toContain(`До ${store.maxVehicleWeightTons} т`);
        expect(label, `розмір слота філії ${store.externalId}`).toContain(`Слот ${store.slotSizeMinutes} хв`);
      }
    }
  });

  test('S-04.2 пошук за адресою і за номером філії', async ({ page }) => {
    const city = 'Київ';
    const stores = await api.stores(city);
    // Беремо філію з кінця списку — щоб пошук не «випадково» знаходив першу.
    const target = stores.items[stores.items.length - 1];

    await loginSupplier(page);
    await goto(page, `/booking/cities/${encodeURIComponent(city)}`);
    const search = page.locator('#branch-search');
    const cards = page.locator('.branch-grid li');

    await search.fill(target.externalId);
    await page.waitForTimeout(400);
    const byId = stores.items.filter(
      (s) => s.externalId.includes(target.externalId) || s.address.toLowerCase().includes(target.externalId),
    ).length;
    expect(await cards.count(), `пошук за номером ${target.externalId}`).toBe(byId);
    await expect(cards.first()).toContainText(target.externalId);

    const fragment = target.address.slice(0, 12);
    await search.fill(fragment);
    await page.waitForTimeout(400);
    const byAddress = stores.items.filter((s) => s.address.toLowerCase().includes(fragment.toLowerCase())).length;
    expect(await cards.count(), `пошук за адресою «${fragment}»`).toBe(byAddress);

    // Регістр не має значення.
    await search.fill(fragment.toUpperCase());
    await page.waitForTimeout(400);
    expect(await cards.count(), 'пошук нечутливий до регістру').toBe(byAddress);
  });

  test('S-04.3 X-04 порожній результат пошуку філій', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, `/booking/cities/${encodeURIComponent('Київ')}`);
    await page.locator('#branch-search').fill('вулиця-якої-немає');
    await page.waitForTimeout(400);
    await expect(page.locator('.empty-state')).toHaveText('Філій не знайдено');
  });
});

test.describe('S-05 Сітка слотів', () => {
  test('S-05.1 X-01 сітка збігається з відповіддю API по рампах, рядках і станах', async ({ page }) => {
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(1);

    await loginSupplier(page);
    await goto(page, `/booking/stores/${store.storeId}`);
    await selectGridDate(page, date);

    const grid = await api.slots(store.storeId, date);
    const rowStarts = [...new Set(grid.slots.map((s) => s.localStart))];

    await expect(page.locator('.slot-grid')).toBeVisible();
    const headers = await page.locator('.slot-grid thead th').allInnerTexts();
    expect(headers.length - 1, `колонок має бути стільки ж, скільки рамп (${store.ramps.length})`).toBe(
      store.ramps.length,
    );
    // Заголовки набрані капітеллю через CSS, тому порівнюємо без регістру.
    const headerLine = headers.join(' ').toLocaleLowerCase('uk-UA');
    for (const ramp of store.ramps) {
      expect(headerLine, `рампа ${ramp.name} має бути колонкою`).toContain(ramp.name.toLocaleLowerCase('uk-UA'));
    }

    expect(await page.locator('.slot-grid tbody tr').count(), `рядків має бути ${rowStarts.length}`).toBe(
      rowStarts.length,
    );

    const times = await page.locator('.slot-grid tbody tr th').allInnerTexts();
    expect(times.map(normalizedText), 'час рядків має збігатися з API').toEqual(rowStarts);

    // Клікабельні рівно ті слоти, які API позначив selectable.
    const selectable = grid.slots.filter((s) => s.selectable).length;
    expect(await page.locator('button.slot').count(), `вільних (клікабельних) слотів має бути ${selectable}`).toBe(
      selectable,
    );

    const labels: Record<string, string> = {
      available: 'Вільно',
      held: 'Оформлюється',
      booked: 'Зайнято',
      reserved: 'Недоступно',
      blocked: 'Заблоковано',
      past: 'Минув',
    };
    const cellLabels = (await page.locator('.slot').allInnerTexts()).map(normalizedText);
    for (const state of new Set(grid.slots.map((s) => s.state))) {
      const expectedCount = grid.slots.filter((s) => s.state === state && !s.reservedForYou).length;
      if (expectedCount === 0) {
        continue;
      }
      const shown = cellLabels.filter((label) => label === labels[state]).length;
      expect(shown, `слотів у стані «${labels[state]}» має бути ${expectedCount}`).toBe(expectedCount);
    }
  });

  test('S-05.2 стрічка дат: 7 днів, навігація в межах горизонту, вибір філії зберігається', async ({ page }) => {
    const store = kharkiv[0];
    const today = kyivToday();

    await loginSupplier(page);
    await goto(page, `/booking/stores/${store.storeId}`);

    const dates = page.locator('.dates .date');
    await expect(dates).toHaveCount(7);
    await expect(page.locator('.dates > button').first(), 'на сьогодні «назад» має бути вимкнено').toBeDisabled();

    const header = normalizedText(await page.locator('.page__head').innerText());
    expect(header, 'у шапці має бути горизонт бронювання').toContain(`Горизонт бронювання — ${store.bookingHorizonDays}`);

    const next = page.locator('.dates > button').last();
    let guard = 0;
    while (!(await next.isDisabled()) && guard < 10) {
      await next.click();
      await page.waitForTimeout(700);
      guard++;
    }
    expect(guard, 'кнопка «Наступні дні» має врешті вимкнутись').toBeLessThan(10);

    const lastLabels = await dates.allInnerTexts();
    const lastDay = normalizedText(lastLabels[lastLabels.length - 1]);
    const horizonDate = shiftDate(today, store.bookingHorizonDays);
    const horizonLabel = new Intl.DateTimeFormat('uk-UA', {
      day: 'numeric',
      month: 'long',
      timeZone: 'Europe/Kyiv',
    }).format(new Date(`${horizonDate}T12:00:00Z`));
    expect(lastDay, `далі за горизонт (${store.bookingHorizonDays} дн.) стрічка йти не має`).toContain(horizonLabel);

    // Вибір філії не загубився при перемиканні дат.
    await expect(page).toHaveURL(new RegExp(store.storeId));
    expect(normalizedText(await page.locator('.page__head h1').innerText())).toBe(store.address);
  });

  test('S-05.3 X-04 день без прийому показує зрозуміле повідомлення', async ({ page }) => {
    const store = kharkiv[0];
    const sunday = nearestSunday(1);
    test.skip(diffDays(kyivToday(), sunday) > 6, 'найближча неділя поза видимою стрічкою');

    const grid = await api.slots(store.storeId, sunday);
    test.skip(grid.slots.length > 0, 'на цю неділю філія працює — перевірка не застосовна');

    await loginSupplier(page);
    await goto(page, `/booking/stores/${store.storeId}`);
    await selectGridDate(page, sunday);

    await expect(page.locator('.empty-state')).toHaveText('На цю дату слотів немає');
  });

  test('S-05.4 легенда описує всі стани слота', async ({ page }) => {
    const store = kharkiv[0];
    await loginSupplier(page);
    await goto(page, `/booking/stores/${store.storeId}`);

    const legend = normalizedText(await page.locator('.legend').innerText());
    for (const label of ['Вільно', 'Ваш резерв', 'Оформлюється', 'Зайнято', 'Недоступно', 'Заблоковано', 'Минув']) {
      expect(legend, `легенда має пояснювати стан «${label}»`).toContain(label);
    }
  });

  test('S-05.5 зайнятий слот показаний і неклікабельний', async ({ page }) => {
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const date = workingDay(2);
    const grid = await api.slots(store.storeId, date);
    const free = grid.slots.find((s) => s.state === 'available');
    expect(free, 'потрібен вільний слот для перевірки').toBeTruthy();

    const booking = await api.createBooking({
      storeId: store.storeId,
      rampId: free!.rampId,
      slotStart: free!.slotStart,
      plateNumber: 'UT7777XX',
      weightTons: 3,
      palletsCount: 5,
      orderId: 'UITEST-busy',
    });

    try {
      await loginSupplier(page);
      await goto(page, `/booking/stores/${store.storeId}`);
      await selectGridDate(page, date);

      const column = store.ramps.findIndex((r) => r.rampId === free!.rampId);
      const cell = cellAt(page, `${free!.localStart}`, column);

      await expect(cell.locator('.slot'), 'зайнятий слот має бути підписаний').toHaveText('Зайнято');
      expect(await cell.locator('button.slot').count(), 'зайнятий слот не має бути кнопкою').toBe(0);
      await expect(cell.locator('span.slot')).toHaveAttribute('aria-disabled', 'true');

      // Клік по недоступному слоту не має нічого відкривати.
      await cell.click({ force: true });
      await page.waitForTimeout(700);
      expect(await page.locator('.panel').count(), 'панель бронювання не має відкритись').toBe(0);
    } finally {
      await api.cancelBooking(booking.id);
    }
  });

  test('S-05.10 підписи рамп не дублюють слово «Рампа»', async ({ page }) => {
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    await loginSupplier(page);
    await goto(page, `/booking/stores/${store.storeId}`);
    await expect(page.locator('.slot-grid')).toBeVisible();

    const headers = (await page.locator('.slot-grid thead th').allInnerTexts()).map(normalizedText);
    const doubled = headers.filter((h) => /рампа\s+рампа/i.test(h));
    expect(doubled, `заголовки колонок: ${headers.join(' | ')}`).toHaveLength(0);

    const aria = await page.locator('.slot').first().getAttribute('aria-label');
    expect(aria ?? '', 'підпис для читача екрана також не має дублювати слово').not.toMatch(/рампа\s+рампа/i);
  });

  test('S-05.6 X-08 під час завантаження сітки видно індикатор', async ({ page }) => {
    const store = kharkiv[0];
    await loginSupplier(page);

    await page.route('**/slots?*', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 2500));
      await route.continue();
    });

    await page.goto(HOSTS.supplier + `/booking/stores/${store.storeId}`);
    await expect(page.locator('.spinner').first(), 'має зʼявитись індикатор завантаження').toBeVisible({
      timeout: 10_000,
    });
    await expect(page.locator('body')).toContainText('Завантаження…');
    await page.unroute('**/slots?*');
  });

  test('S-05.7 X-06 недоступна філія повідомляє про це текстом', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/booking/stores/00000000-0000-4000-8000-000000000000');
    await page.waitForTimeout(1500);

    const text = await bodyText(page);
    expect(
      /недоступна вашому підприємству|не знайдено|помилка/i.test(text),
      `екран має пояснити проблему, а не мовчати. Текст: ${text.slice(0, 300)}`,
    ).toBe(true);
  });

  test('S-05.8 X-07 екрани вибору без ключів перекладу і англійських слів', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv[0];

    for (const path of [
      '/booking/cities',
      `/booking/cities/${encodeURIComponent('Харків')}`,
      `/booking/stores/${store.storeId}`,
    ]) {
      await goto(page, path);
      await page.waitForTimeout(500);
      const problems = languageProblems(await bodyText(page));
      expect(problems, `${path}: неперекладені фрагменти ${problems.join(', ')}`).toHaveLength(0);
    }
  });

  test('S-05.9 X-10 адаптивність 360/768/1280 без горизонтального скролу', async ({ page }) => {
    await loginSupplier(page);
    const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
    const screens = [
      '/home',
      '/booking/cities',
      `/booking/cities/${encodeURIComponent('Київ')}`,
      `/booking/stores/${store.storeId}`,
      '/route-sheets',
      // Найширші таблиці кабінету — саме тут найімовірніший горизонтальний скрол.
      `/route-sheets/${kyivToday()}`,
      `/route-sheets/${kyivToday()}/print`,
      '/vehicles',
      '/drivers',
    ];
    const failures: string[] = [];

    for (const size of [
      { width: 360, height: 780 },
      { width: 768, height: 1024 },
      { width: 1280, height: 900 },
    ]) {
      await page.setViewportSize(size);
      for (const path of screens) {
        await goto(page, path);
        await page.waitForTimeout(400);
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        if (overflow > 1) {
          failures.push(`${size.width}px ${path}: зайві ${overflow}px по горизонталі`);
        }
      }
    }

    expect(failures, failures.join('; ')).toHaveLength(0);
  });
});
