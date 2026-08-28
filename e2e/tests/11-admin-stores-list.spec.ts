/**
 * A-02. Список магазинів: повнота, пошук, фільтри, пагінація.
 *
 * Кожне очікуване число береться з API того самого стенду — тест питає бекенд
 * «скільки насправді записів» і звіряє з тим, що показує інтерфейс.
 */
import { expect, test } from '@playwright/test';
import {
  apiCities,
  apiStoreTotal,
  apiStores,
  bodyText,
  dataRowCount,
  goto,
  loginAdmin,
  multiSelectOptions,
  multiSelectPick,
  multiSelectSearch,
  paginationPages,
  paginationTotal,
} from '../support/admin';

test.beforeEach(async ({ page }) => {
  await loginAdmin(page);
});

/** Очікування відповіді списку магазинів. */
function storesResponse(page: import('@playwright/test').Page) {
  return page.waitForResponse(
    (r) => r.url().includes('/api/admin/v1/stores?') && r.request().method() === 'GET',
    { timeout: 20_000 },
  );
}

/**
 * Натиснути «Застосувати».
 *
 * Запиту може й не бути: якщо вибір фільтра вже переписав адресу, роутер
 * повторної навігації не робить. Тому чекаємо не на запит, а на спокій мережі.
 */
async function apply(page: import('@playwright/test').Page): Promise<void> {
  await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);
}

/** Лічильник «Усього» з очікуванням: список довантажується асинхронно. */
async function expectTotal(
  page: import('@playwright/test').Page,
  expected: number,
  message: string,
): Promise<void> {
  await expect
    .poll(() => paginationTotal(page), { message, timeout: 15_000 })
    .toBe(expected);
}

test.describe('A-02 Список магазинів', () => {
  test('A-02.1 X-01 загальна кількість збігається з API', async ({ page }) => {
    const expected = await apiStoreTotal();
    await goto(page, '/stores');

    expect(await paginationTotal(page), 'лічильник «Усього» має дорівнювати кількості в API').toBe(
      expected,
    );
    expect(await dataRowCount(page), 'перша сторінка при perPage=20').toBe(Math.min(20, expected));
  });

  test('A-02.2 X-03 пагінація до останньої сторінки', async ({ page }) => {
    const total = await apiStoreTotal();
    await goto(page, '/stores');

    const { pages } = await paginationPages(page);
    expect(pages, 'кількість сторінок = ceil(total / 20)').toBe(Math.ceil(total / 20));

    await goto(page, `/stores?page=${pages}&pageSize=20`);
    const state = await paginationPages(page);
    expect(state.page, 'ми на останній сторінці').toBe(pages);
    expect(await paginationTotal(page), 'лічильник не змінюється між сторінками').toBe(total);

    const expectedOnLast = total - (pages - 1) * 20;
    expect(await dataRowCount(page), 'на останній сторінці — залишок записів').toBe(expectedOnLast);

    // кнопка «вперед» на останній сторінці має бути недоступна
    const next = page.locator('.pagination button').last();
    await expect(next, 'на останній сторінці «вперед» вимкнена').toBeDisabled();
  });

  test('A-02.3 X-03 perPage 20 / 50 / 100', async ({ page }) => {
    const total = await apiStoreTotal();
    for (const size of [20, 50, 100]) {
      await goto(page, `/stores?page=1&pageSize=${size}`);
      expect(await dataRowCount(page), `perPage=${size}: рядків на сторінці`).toBe(
        Math.min(size, total),
      );
      expect((await paginationPages(page)).pages, `perPage=${size}: кількість сторінок`).toBe(
        Math.ceil(total / size),
      );
      expect(await paginationTotal(page), `perPage=${size}: загальна кількість`).toBe(total);
    }
  });

  test('A-02.4 перемикач розміру сторінки в інтерфейсі працює', async ({ page }) => {
    await goto(page, '/stores');
    await Promise.all([
      storesResponse(page),
      page.locator('select[aria-label=page-size]').selectOption('100'),
    ]);
    await page.waitForLoadState('networkidle');
    // Відповідь приходить раніше, ніж таблиця перемальовується, тому чекаємо
    // на рядки, а не на подію мережі — так само, як expectTotal вище.
    await expect
      .poll(() => dataRowCount(page), { message: 'після вибору 100 рядків має бути 100' })
      .toBe(100);
  });

  test('A-02.5 X-02 пошук за externalId — точний збіг', async ({ page }) => {
    const target = '2229';
    const expected = await apiStores(`q=${target}&perPage=20`);

    await goto(page, '/stores');
    await page.locator('#store-search').fill(target);
    await apply(page);

    await expectTotal(page, expected.total, `API для q=${target} віддає ${expected.total}`);
    expect(await dataRowCount(page)).toBe(expected.items.length);
    await expect(page.locator('table.data tbody')).toContainText(target);
  });

  test('A-02.6 X-02 пошук за фрагментом адреси', async ({ page }) => {
    const term = 'Гвардійців';
    const expected = await apiStores(`q=${encodeURIComponent(term)}&perPage=100`);
    expect(expected.total, 'для перевірки потрібен хоч один збіг у базі').toBeGreaterThan(0);

    await goto(page, '/stores');
    await page.locator('#store-search').fill(term);
    await apply(page);

    await expectTotal(page, expected.total, 'UI має показати стільки ж, скільки API');
    const text = await bodyText(page);
    for (const item of expected.items.slice(0, 20)) {
      expect(text, `філія ${item.externalId} має бути в результатах`).toContain(item.externalId);
    }
  });

  test('A-02.7 X-02 пошук знаходить філію з «дальньої» сторінки', async ({ page }) => {
    // Беремо запис із передостанньої сторінки повного списку: у базовій
    // видачі його на першій сторінці свідомо немає.
    const total = await apiStoreTotal();
    const lastPage = Math.ceil(total / 100);
    const far = await apiStores(`perPage=100&page=${lastPage}`);
    const target = far.items.find((i) => i.address && i.city);
    expect(target, 'на дальній сторінці має бути придатний запис').toBeTruthy();

    await goto(page, '/stores');
    const firstPageText = await bodyText(page);
    expect(
      firstPageText.includes(target!.externalId),
      'контроль: цієї філії не має бути на першій сторінці',
    ).toBe(false);

    await page.locator('#store-search').fill(target!.address);
    await apply(page);

    const expected = await apiStores(`q=${encodeURIComponent(target!.address)}&perPage=100`);
    await expectTotal(page, expected.total, 'кількість знайденого збігається з API');
    expect(await bodyText(page), 'пошук за адресою знаходить філію з дальньої сторінки').toContain(
      target!.externalId,
    );
  });

  test('A-02.8 X-01 фільтр «Місто» містить усі міста з API', async ({ page }) => {
    const cities = await apiCities();
    await goto(page, '/stores');

    const options = await multiSelectOptions(page, 'Місто');
    const missing = cities
      .map((c) => c.city)
      .filter((city) => !options.some((o) => o.startsWith(city + ' (')));

    expect(
      missing,
      `у випадному списку ${options.length} міст із ${cities.length}; немає: ${missing.join(', ')}`,
    ).toEqual([]);
  });

  test('A-02.9 фільтр «Місто» показує коректний лічильник філій', async ({ page }) => {
    const cities = await apiCities();
    await goto(page, '/stores');
    const options = await multiSelectOptions(page, 'Місто');

    const wrong: string[] = [];
    for (const c of cities) {
      const option = options.find((o) => o.startsWith(c.city + ' ('));
      if (option !== `${c.city} (${c.storeCount})`) {
        wrong.push(`${c.city}: у списку «${option}», в API ${c.storeCount}`);
      }
    }
    expect(wrong, wrong.join('; ')).toEqual([]);
  });

  test('A-02.10 X-02 пошук у списку міст знаходить «дальнє» місто', async ({ page }) => {
    const cities = await apiCities();
    // Місто з кінця абеткового списку — свідомо не на початку дропдауна.
    const far = cities[cities.length - 1];
    await goto(page, '/stores');

    const found = await multiSelectSearch(page, 'Місто', far.city);
    expect(
      found.some((o) => o.startsWith(far.city + ' (')),
      `пошук «${far.city}» у фільтрі міст має його знайти`,
    ).toBe(true);
  });

  test('A-02.11 фільтр за містом дає ту саму кількість, що й API', async ({ page }) => {
    const expected = await apiStores(`city=${encodeURIComponent('Київ')}&perPage=20`);
    await goto(page, '/stores');
    await multiSelectPick(page, 'Місто', 'Київ (');
    await apply(page);

    await expectTotal(page, expected.total, 'у Києві філій за даними API');
    expect(page.url(), 'фільтр зберігається в адресі (deep-link)').toContain('cities=');
  });

  test('A-02.12 фільтр за статусом дає ту саму кількість, що й API', async ({ page }) => {
    const expected = await apiStores('ymsStatus=active&perPage=20');
    await goto(page, '/stores');
    await multiSelectPick(page, 'Статус YMS', 'Активний');
    await apply(page);
    await expectTotal(page, expected.total, 'активних філій за даними API');
  });

  test('A-02.13 фільтр «Налаштованість»', async ({ page }) => {
    for (const [value, query] of [
      ['true', 'configured=true'],
      ['false', 'configured=false'],
    ] as const) {
      const expected = await apiStores(`${query}&perPage=20`);
      await goto(page, '/stores');
      await page.locator('#configured').selectOption(value);
      await page.waitForLoadState('networkidle');
      await expectTotal(page, expected.total, `configured=${value}`);
    }
  });

  test('A-02.14 комбінація фільтрів місто + статус', async ({ page }) => {
    const query = `city=${encodeURIComponent('Харків')}&ymsStatus=active&perPage=20`;

    await goto(page, '/stores');
    await multiSelectPick(page, 'Місто', 'Харків (');
    await multiSelectPick(page, 'Статус YMS', 'Активний');

    // Очікуване значення — рухома ціль: харківські філії-пісочниці паралельно
    // перемикають статус інші набори (пауза/активація, сценарії прибуття).
    // Тому список і API перечитуються РАЗОМ, доки не збігатимуться: перевірка
    // лишається справжньою (інтерфейс мусить показувати те саме, що бекенд),
    // але не падає через зміну, яка сталася між двома читаннями.
    await expect(async () => {
      await apply(page);
      const expected = await apiStores(query);
      expect(await paginationTotal(page)).toBe(expected.total);
      expect(await dataRowCount(page)).toBe(expected.items.length);
    }).toPass({ timeout: 30_000 });
  });

  test('A-02.15 X-03 фільтри зберігаються при переході між сторінками', async ({ page }) => {
    const expected = await apiStores(`city=${encodeURIComponent('Київ')}&perPage=20`);
    await goto(page, '/stores');
    await multiSelectPick(page, 'Місто', 'Київ (');
    await apply(page);

    await page.locator('.pagination button').last().click();
    await page.waitForLoadState('networkidle');

    await expect
      .poll(async () => (await paginationPages(page)).page, { message: 'ми на другій сторінці' })
      .toBe(2);
    await expectTotal(page, expected.total, 'фільтр міста лишився чинним');
    expect(page.url()).toContain('cities=');
  });

  test('A-02.16 скидання фільтрів повертає повний список', async ({ page }) => {
    const total = await apiStoreTotal();
    await goto(page, '/stores');
    await multiSelectPick(page, 'Місто', 'Київ (');
    await apply(page);
    await expect.poll(() => paginationTotal(page)).toBeLessThan(total);

    await page.locator('.toolbar button', { hasText: 'Скинути фільтри' }).click();
    await page.waitForLoadState('networkidle');

    await expectTotal(page, total, 'після скидання — увесь список');
    expect(await page.locator('#store-search').inputValue(), 'поле пошуку очищено').toBe('');
  });

  test('A-02.17 X-04 порожній стан має свідоме повідомлення', async ({ page }) => {
    const expected = await apiStores('q=ZZZ-NO-SUCH-STORE&perPage=20');
    expect(expected.total).toBe(0);

    await goto(page, '/stores');
    await page.locator('#store-search').fill('ZZZ-NO-SUCH-STORE');
    await apply(page);

    await expect.poll(() => dataRowCount(page), { message: 'рядків немає' }).toBe(0);
    await expect(page.locator('app-empty-state')).toBeVisible();
    await expect(page.locator('app-empty-state')).toContainText(
      expected.emptyMessage ?? 'не знайдено',
    );
  });

  test('A-02.18 сортування за колонкою відображається у видачі', async ({ page }) => {
    await goto(page, '/stores?pageSize=20&sort=externalId&dir=asc');
    const asc = await apiStores('perPage=20&page=1&sortBy=externalId&sortDirection=asc');
    const uiAsc = await page.locator('table.data tbody tr td.mono a').allInnerTexts();
    expect(uiAsc, 'сортування за кодом філії за зростанням').toEqual(
      asc.items.map((i) => i.externalId),
    );

    await goto(page, '/stores?pageSize=20&sort=externalId&dir=desc');
    const desc = await apiStores('perPage=20&page=1&sortBy=externalId&sortDirection=desc');
    const uiDesc = await page.locator('table.data tbody tr td.mono a').allInnerTexts();
    expect(uiDesc, 'сортування за спаданням').toEqual(desc.items.map((i) => i.externalId));
  });

  test('A-02.20 фільтром за містом досяжні всі магазини мережі', async ({ page }) => {
    const cities = await apiCities();
    const total = await apiStoreTotal();
    const covered = cities.reduce((sum, c) => sum + c.storeCount, 0);

    await goto(page, '/stores');
    const options = await multiSelectOptions(page, 'Місто');
    const hasEmptyOption = options.some((o) => /^\s*\(/.test(o) || /^—/.test(o));

    expect(
      covered + (hasEmptyOption ? total - covered : 0),
      `сума лічильників у фільтрі міст — ${covered}, усього магазинів — ${total}: ` +
        `${total - covered} філій не потрапляє в жодне значення фільтра ` +
        '(у них порожнє місто, а окремого варіанта «без міста» у списку немає)',
    ).toBe(total);
  });

  test('A-02.19 кожен рядок веде в картку магазину', async ({ page }) => {
    await goto(page, '/stores?q=2226');
    const link = page.locator('table.data tbody tr td.mono a', { hasText: '2226' }).first();
    await link.waitFor({ state: 'visible' });
    await link.click();
    await page.waitForURL(/\/stores\/[0-9a-f-]{8}/, { timeout: 15_000 });
    await page.waitForLoadState('networkidle');
    expect(page.url()).toMatch(/\/stores\/[0-9a-f-]+/);
    await expect(page.locator('.section-nav')).toBeVisible();
  });
});
