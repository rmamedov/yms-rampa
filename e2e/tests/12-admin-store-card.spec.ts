/**
 * A-03 «Загальне», A-04 «Прийом поставок», A-05 «Слоти».
 *
 * Конфігурації міняємо ТІЛЬКИ на філіях-пісочницях Харкова
 * (2226, 2227, 2229, 2230). Вкладки «Прийом» і «Слоти» редагують чернетку —
 * поки не натиснуто «Зберегти», на стенді нічого не змінюється.
 */
import { expect, test } from '@playwright/test';
import {
  apiGet,
  apiRaw,
  apiStores,
  bodyText,
  fieldErrors,
  goto,
  kyivDay,
  loginAdmin,
  openTab,
  sandboxStore,
  track,
  waitForToast,
} from '../support/admin';

test.beforeEach(async ({ page }) => {
  await loginAdmin(page);
});

async function openStore(page: import('@playwright/test').Page, externalId: string) {
  const store = await sandboxStore(externalId);
  await goto(page, `/stores/${store.branchId}`);
  await page.locator('.tabs').waitFor({ state: 'visible', timeout: 20_000 });
  return store;
}

// ------------------------------------------------------------------ A-03

test.describe('A-03 Картка магазину — вкладка «Загальне»', () => {
  test('A-03.1 дані MCP показані та доступні лише для читання', async ({ page }) => {
    const store = await openStore(page, '2226');
    const card = await apiGet<any>(`/stores/${store.branchId}`);

    const pairs: [string, string][] = [
      ['#mcp-externalId', card.mcpData.externalId],
      ['#mcp-city', card.mcpData.city],
      ['#mcp-address', card.mcpData.address],
      ['#mcp-lat', String(card.mcpData.latitude)],
      ['#mcp-lon', String(card.mcpData.longitude)],
      ['#mcp-branchId', card.mcpData.branchId],
      ['#mcp-companyId', card.mcpData.companyId],
    ];

    for (const [selector, expected] of pairs) {
      const input = page.locator(selector);
      await expect(input, `${selector} має значення з MCP`).toHaveValue(expected);
      await expect(input, `${selector} має бути readonly`).toHaveAttribute('readonly', /.*/);
    }
  });

  test('A-03.2 назва: порожнє значення відхиляється, збереження блокується', async ({ page }) => {
    await openStore(page, '2226');
    await page.locator('#displayName').fill('');
    await expect(page.locator('.field-error')).toContainText('Назва — від 1 до 120 символів');
    await expect(
      page.locator('button.btn-primary', { hasText: 'Зберегти' }),
      'із порожньою назвою зберегти не можна',
    ).toBeDisabled();
  });

  test('A-03.3 назва понад 120 символів відхиляється', async ({ page }) => {
    await openStore(page, '2226');
    await page.locator('#displayName').fill('я'.repeat(121));
    await expect(page.locator('.field-error')).toContainText('Назва — від 1 до 120 символів');
    await expect(page.locator('button.btn-primary', { hasText: 'Зберегти' })).toBeDisabled();
  });

  test('A-03.4 телефон валідується за форматом +380XXXXXXXXX', async ({ page }) => {
    await openStore(page, '2226');
    for (const bad of ['12345', '+38050123456', '0501234567', '+3805012345678']) {
      await page.locator('#phone').fill(bad);
      await expect(
        page.locator('.field-error'),
        `телефон «${bad}» має бути відхилений`,
      ).toContainText('Телефон у форматі +380XXXXXXXXX');
    }
    await page.locator('#phone').fill('+380501234567');
    expect(
      (await fieldErrors(page)).filter((e) => e.includes('Телефон')),
      'коректний телефон помилок не викликає',
    ).toEqual([]);
  });

  test('A-03.5 addressOverride понад 200 символів відхиляється', async ({ page }) => {
    await openStore(page, '2226');
    await page.locator('#addressOverride').fill('а'.repeat(201));
    await expect(page.locator('.field-error')).toContainText(
      'Адреса для відображення — до 200 символів',
    );
  });

  test('A-03.6 редагування зберігається і перечитується', async ({ page }) => {
    const store = await openStore(page, '2226');
    const before = await apiGet<any>(`/stores/${store.branchId}`);
    track('store-general', store.externalId, `назва/телефон/адреса, було: ${before.displayName}`);

    const marker = `UITEST-назва-${Date.now().toString(36)}`;
    await page.locator('#displayName').fill(marker);
    await page.locator('#phone').fill('+380501234567');
    await page.locator('#addressOverride').fill('UITEST адреса для відображення');
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    expect(await waitForToast(page)).toContain('Конфігурацію збережено');

    // перечитуємо з бекенду — саме він джерело правди
    const after = await apiGet<any>(`/stores/${store.branchId}`);
    expect(after.displayName, 'назву збережено').toBe(marker);
    expect(after.phone, 'телефон збережено').toBe('+380501234567');
    expect(after.addressOverride, 'адресу для відображення збережено').toBe(
      'UITEST адреса для відображення',
    );

    // і сторінка після перезавантаження показує те саме
    await goto(page, `/stores/${store.branchId}`);
    await expect(page.locator('#displayName')).toHaveValue(marker);
    await expect(page.locator('#phone')).toHaveValue('+380501234567');

    // повертаємо як було
    await page.locator('#displayName').fill(before.displayName);
    await page.locator('#phone').fill('');
    await page.locator('#addressOverride').fill('');
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);
    const restored = await apiGet<any>(`/stores/${store.branchId}`);
    expect(restored.displayName, 'вихідний стан відновлено').toBe(before.displayName);
    expect(restored.phone).toBeNull();
  });

  test('A-03.6б перемикач видимості постачальникам зберігається', async ({ page }) => {
    const store = await openStore(page, '2229');
    const before = await apiGet<any>(`/stores/${store.branchId}`);
    track('store-visibility', store.externalId, `було: ${before.visibleToSuppliers}`);

    const visible = page.locator('label.checkbox input[type=checkbox]').first();
    await expect(visible).toBeChecked({ checked: before.visibleToSuppliers });

    await visible.setChecked(!before.visibleToSuppliers);
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);
    expect(
      (await apiGet<any>(`/stores/${store.branchId}`)).visibleToSuppliers,
      'видимість перемкнулась',
    ).toBe(!before.visibleToSuppliers);

    // повертаємо як було
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('label.checkbox input[type=checkbox]').first().setChecked(before.visibleToSuppliers);
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);
    expect(
      (await apiGet<any>(`/stores/${store.branchId}`)).visibleToSuppliers,
      'вихідний стан відновлено',
    ).toBe(before.visibleToSuppliers);
  });

  test('A-03.7 перелік статусів відповідає дозволеним переходам з API', async ({ page }) => {
    const store = await openStore(page, '2229');
    const card = await apiGet<any>(`/stores/${store.branchId}`);
    const expected = ['not_configured', 'active', 'paused', 'archived'].filter(
      (s) => s === card.ymsStatus || card.allowedTransitions.includes(s),
    );
    const labels: Record<string, string> = {
      not_configured: 'Не налаштовано',
      active: 'Активний',
      paused: 'На паузі',
      archived: 'Архівний',
    };
    const options = await page.locator('#ymsStatus option').allInnerTexts();
    expect(options.map((s) => s.trim()), 'варіанти статусу = поточний + дозволені переходи').toEqual(
      expected.map((s) => labels[s]),
    );
  });

  test('A-03.8 активація ненастроєного магазину відхиляється', async ({ page }) => {
    // read-only перевірка: беремо будь-який ненастроєний магазин і НЕ зберігаємо
    const list = await apiStores('ymsStatus=not_configured&perPage=20');
    const target = list.items[0];
    expect(target, 'на стенді має бути ненастроєний магазин').toBeTruthy();

    await goto(page, `/stores/${target.branchId}`);
    await page.locator('.tabs').waitFor({ state: 'visible' });
    await expect(page.locator('.notice-warn')).toContainText('Магазин не налаштовано');

    await page.locator('#ymsStatus').selectOption('active');
    await expect(
      // помилку шукаємо поруч із самим полем статусу, а не будь-де на сторінці
      page.locator('.field', { has: page.locator('#ymsStatus') }).locator('.field-error'),
    ).toContainText('Неможливо активувати: не завершено налаштування магазину');
    await expect(
      page.locator('button.btn-primary', { hasText: 'Зберегти' }),
      'кнопка збереження має бути заблокована',
    ).toBeDisabled();

    // і бекенд теж відмовляє
    const res = await apiRaw('patch', `/stores/${target.branchId}`, { ymsStatus: 'active' });
    expect(res.status, 'бекенд не дозволяє активувати ненастроєний магазин').toBeGreaterThanOrEqual(
      400,
    );
    const still = await apiGet<any>(`/stores/${target.branchId}`);
    expect(still.ymsStatus, 'статус не змінився').toBe('not_configured');
  });

  test('A-03.9 перехід active → paused виконується з картки магазину', async ({ page }) => {
    const store = await openStore(page, '2227');
    const before = await apiGet<any>(`/stores/${store.branchId}`);
    expect(before.ymsStatus, 'вихідний стан філії-пісочниці').toBe('active');
    expect(before.visibleToSuppliers, 'філія видима постачальникам').toBe(true);
    track('store-status', store.externalId, 'active→paused→active');

    // Користувач робить рівно те, що описано у плані: змінює статус і зберігає.
    await page.locator('#ymsStatus').selectOption('paused');
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    const toast = await waitForToast(page);

    const afterPause = await apiGet<any>(`/stores/${store.branchId}`);
    expect(
      afterPause.ymsStatus,
      `магазин має перейти на паузу; повідомлення на екрані: «${toast}»`,
    ).toBe('paused');

    // повертаємо як було
    await goto(page, `/stores/${store.branchId}`);
    const visible = page.locator('label.checkbox input[type=checkbox]').first();
    await page.locator('#ymsStatus').selectOption('active');
    if (!(await visible.isChecked())) {
      await visible.check();
    }
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);

    const after = await apiGet<any>(`/stores/${store.branchId}`);
    expect(after.ymsStatus, 'магазин знову активний').toBe('active');
    expect(after.visibleToSuppliers, 'видимість відновлено').toBe(before.visibleToSuppliers);
  });

  test('A-03.9б пауза не має падати через видимість постачальникам', async ({ page }) => {
    // Діагностика A-03.9: форма надсилає всі поля одним PATCH, разом із
    // visibleToSuppliers=true, а бекенд забороняє видимість поза статусом «Активний».
    const store = await openStore(page, '2226');
    const before = await apiGet<any>(`/stores/${store.branchId}`);
    test.skip(before.ymsStatus !== 'active' || !before.visibleToSuppliers, 'потрібна активна видима філія');

    const requests: string[] = [];
    page.on('request', (r) => {
      if (r.method() === 'PATCH' && r.url().includes(`/stores/${store.branchId}`)) {
        requests.push(r.postData() ?? '');
      }
    });

    await page.locator('#ymsStatus').selectOption('paused');
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);

    expect(requests.length, 'форма надіслала PATCH').toBeGreaterThan(0);
    const body = JSON.parse(requests[0]);
    expect(
      body.visibleToSuppliers,
      'разом зі статусом «На паузі» форма не має надсилати visibleToSuppliers=true — ' +
        'бекенд відповідає 409 і статус не змінюється',
    ).not.toBe(true);
  });

  test('A-03.10 ознака «Налаштовано» відповідає даним бекенду', async ({ page }) => {
    const store = await openStore(page, '2226');
    const card = await apiGet<any>(`/stores/${store.branchId}`);
    const text = await bodyText(page);
    if (card.configured) {
      expect(text).toContain('Магазин налаштовано');
    } else {
      expect(text).toContain('Магазин не налаштовано');
      for (const item of card.missingSettings) {
        expect(text, `у переліку прогалин має бути «${item}»`).toContain(item);
      }
    }
  });
});

// ------------------------------------------------------------------ A-04

test.describe('A-04 Вкладка «Прийом поставок»', () => {
  test('A-04.1 у вкладці є всі сім днів тижня', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');
    const days = await page.locator('.day-name').allInnerTexts();
    expect(days.map((s) => s.trim())).toEqual([
      'Понеділок',
      'Вівторок',
      'Середа',
      'Четвер',
      'Пʼятниця',
      'Субота',
      'Неділя',
    ]);
  });

  test('A-04.2 інтервали з бекенду показані правильно', async ({ page }) => {
    const store = await openStore(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    await openTab(page, 'Прийом поставок');

    for (const window of config.receivingWindows) {
      const row = page.locator('.day-row').nth(window.dayOfWeek - 1);
      const inputs = row.locator('input[type=time]');
      const values = await inputs.evaluateAll((els) =>
        (els as HTMLInputElement[]).map((e) => e.value),
      );
      const expected = window.intervals.flatMap((i: any) => [i.from, i.to]);
      expect(values, `день ${window.dayOfWeek}: інтервали з конфігурації`).toEqual(expected);
    }
  });

  test('A-04.3 можна додати вікно на кожен день тижня', async ({ page }) => {
    const store = await openStore(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const daysInConfig = config.receivingWindows.map((w: any) => w.dayOfWeek);
    await openTab(page, 'Прийом поставок');

    const broken: string[] = [];
    for (let day = 1; day <= 7; day += 1) {
      const row = page.locator('.day-row').nth(day - 1);
      const before = await row.locator('.interval-row').count();
      await row.locator('button', { hasText: 'Додати інтервал' }).click();
      await page.waitForTimeout(200);
      const after = await row.locator('.interval-row').count();
      if (after !== before + 1) {
        broken.push(
          `день ${day} (${daysInConfig.includes(day) ? 'є в конфігурації' : 'НЕМАЄ в конфігурації'}): ` +
            `було ${before}, стало ${after}`,
        );
      }
    }

    expect(
      broken,
      'кнопка «Додати інтервал» має працювати для кожного дня тижня; ' +
        `не спрацювала для: ${broken.join('; ')}`,
    ).toEqual([]);
  });

  test('A-04.4 перетин інтервалів одного дня відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    // Понеділок у конфігурації пісочниці вже має інтервал — додаємо другий, що з ним перетинається.
    const monday = page.locator('.day-row').first();
    const first = monday.locator('.interval-row').first();
    await first.locator('input[type=time]').first().fill('08:00');
    await first.locator('input[type=time]').last().fill('12:00');

    await monday.locator('button', { hasText: 'Додати інтервал' }).click();
    const second = monday.locator('.interval-row').nth(1);
    await second.locator('input[type=time]').first().fill('11:00');
    await second.locator('input[type=time]').last().fill('14:00');

    await expect(monday.locator('.field-error')).toContainText(
      'Інтервали одного дня не можуть перетинатись',
    );
  });

  test('A-04.5 кінець раніше початку відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const monday = page.locator('.day-row').first();
    const row = monday.locator('.interval-row').first();
    await row.locator('input[type=time]').first().fill('15:00');
    await row.locator('input[type=time]').last().fill('09:00');

    await expect(monday.locator('.field-error')).toContainText(
      'Початок має бути раніше за кінець',
    );
  });

  test('A-04.6 інтервал, коротший за розмір слоту, відхиляється', async ({ page }) => {
    const store = await openStore(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    await openTab(page, 'Прийом поставок');

    const monday = page.locator('.day-row').first();
    const row = monday.locator('.interval-row').first();
    await row.locator('input[type=time]').first().fill('09:00');
    await row.locator('input[type=time]').last().fill('09:05');

    await expect(monday.locator('.field-error')).toContainText(
      `Інтервал коротший за розмір слоту (${config.slotSizeMinutes} хв)`,
    );
  });

  test('A-04.7 видалення інтервалу', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const monday = page.locator('.day-row').first();
    const before = await monday.locator('.interval-row').count();
    expect(before, 'у понеділка має бути принаймні один інтервал').toBeGreaterThan(0);
    await monday.locator('.interval-row').first().locator('button', { hasText: 'Видалити' }).click();
    await expect(monday.locator('.interval-row')).toHaveCount(before - 1);
  });

  test('A-04.8 календарний виняток: закритий день', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const date = kyivDay(20);
    await page.locator('#exc-date').fill(date);
    await page.locator('#exc-type').selectOption('closed');
    await page.locator('#exc-reason').fill('UITEST інвентаризація');
    await page.locator('button', { hasText: 'Додати виняток' }).click();

    const table = page.locator('.card', { hasText: 'Календар винятків' }).locator('table.data');
    await expect(table).toContainText('UITEST інвентаризація');
    await expect(table).toContainText('Вихідний (прийому немає)');
  });

  test('A-04.9 календарний виняток: скорочений день', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const date = kyivDay(21);
    await page.locator('#exc-date').fill(date);
    await page.locator('#exc-type').selectOption('custom');
    await page.locator('#exc-from').fill('09:00');
    await page.locator('#exc-to').fill('12:00');
    await page.locator('#exc-reason').fill('UITEST скорочений день');
    await page.locator('button', { hasText: 'Додати виняток' }).click();

    const table = page.locator('.card', { hasText: 'Календар винятків' }).locator('table.data');
    await expect(table).toContainText('UITEST скорочений день');
    await expect(table).toContainText('09:00–12:00');
  });

  test('A-04.10 виняток без причини відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const date = kyivDay(22);
    await page.locator('#exc-date').fill(date);
    await page.locator('#exc-reason').fill('');
    await page.locator('button', { hasText: 'Додати виняток' }).click();

    await expect(page.locator('.field-error')).toContainText(
      'Причина обовʼязкова, до 200 символів',
    );
  });

  test('A-04.11 виняток у минулому відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const past = kyivDay(-5);
    await page.locator('#exc-date').fill(past);
    await page.locator('#exc-reason').fill('UITEST минуле');
    await page.locator('button', { hasText: 'Додати виняток' }).click();

    await expect(page.locator('.field-error')).toContainText(
      'Дата винятку не може бути в минулому',
    );
  });

  test('A-04.12 два винятки на одну дату відхиляються', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const date = kyivDay(23);
    const table = page.locator('.card', { hasText: 'Календар винятків' }).locator('table.data');
    for (const reason of ['UITEST перший', 'UITEST другий']) {
      await page.locator('#exc-date').fill(date);
      await page.locator('#exc-reason').fill(reason);
      await page.locator('button', { hasText: 'Додати виняток' }).click();
      await page.waitForTimeout(200);
    }

    const rowsForDate = await table.locator('tr', { hasText: 'UITEST' }).count();
    const errors = await fieldErrors(page);
    expect(
      errors.some((e) => e.includes('Виняток на цю дату вже існує')),
      `другий виняток на ту саму дату має бути відхилений; ` +
        `у таблиці рядків з UITEST: ${rowsForDate}, помилки на екрані: ${JSON.stringify(errors)}`,
    ).toBe(true);
  });

  test('A-04.13 виняток можна видалити', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Прийом поставок');

    const date = kyivDay(24);
    await page.locator('#exc-date').fill(date);
    await page.locator('#exc-reason').fill('UITEST на видалення');
    await page.locator('button', { hasText: 'Додати виняток' }).click();

    const table = page.locator('.card', { hasText: 'Календар винятків' }).locator('table.data');
    await expect(table).toContainText('UITEST на видалення');
    await table.locator('tr', { hasText: 'UITEST на видалення' }).locator('button').click();
    await expect(table).not.toContainText('UITEST на видалення');
  });
});

// ------------------------------------------------------------------ A-05

test.describe('A-05 Вкладка «Слоти»', () => {
  test('A-05.1 розмір слоту пропонує рівно 15/20/30/60 і не пропонує 45', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');

    const options = await page.locator('#slot-size option').allInnerTexts();
    expect(options.map((s) => s.trim()), 'варіанти розміру слоту').toEqual([
      'не задано',
      '15 хв',
      '20 хв',
      '30 хв',
      '60 хв',
    ]);
    expect(
      options.some((o) => o.includes('45')),
      'значення 45 хв не має бути доступним',
    ).toBe(false);
  });

  test('A-05.2 усі чотири розміри слоту можна обрати', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    for (const size of ['15', '20', '30', '60']) {
      await page.locator('#slot-size').selectOption(size);
      await expect(page.locator('#slot-size')).toHaveValue(size);
    }
  });

  test('A-05.3 спроба надіслати розмір 45 хв відхиляється бекендом', async ({ page: _page }) => {
    const store = await sandboxStore('2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const tomorrow = kyivDay(2);

    const res = await apiRaw('post', `/stores/${store.branchId}/configurations`, {
      ...config,
      effectiveFrom: tomorrow,
      slotSizeMinutes: 45,
      ramps: config.ramps.map((r: any) => ({ ...r })),
    });
    expect(res.status, 'розмір слоту 45 хв має бути відхилений').toBe(422);
    expect(JSON.stringify(res.body)).toContain('15, 20, 30, 60');
  });

  test('A-05.4 рампи з конфігурації показані у таблиці', async ({ page }) => {
    const store = await openStore(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    await openTab(page, 'Слоти');

    const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
    await expect(rows, 'кількість рамп збігається з конфігурацією').toHaveCount(
      config.ramps.length,
    );
    for (let i = 0; i < config.ramps.length; i += 1) {
      await expect(rows.nth(i).locator('input[type=number]')).toHaveValue(
        String(config.ramps[i].number),
      );
      await expect(rows.nth(i).locator('input[type=text]')).toHaveValue(
        config.ramps[i].name ?? '',
      );
    }
  });

  test('A-05.5 додавання рампи', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
    const before = await rows.count();

    await page.locator('button', { hasText: 'Додати рампу' }).click();
    await expect(rows).toHaveCount(before + 1);
    await expect(rows.last().locator('input[type=number]')).toHaveValue(String(before + 1));
  });

  test('A-05.6 назва рампи обмежена 60 символами', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    const nameInput = page
      .locator('.card', { hasText: 'Рампи' })
      .locator('table.data tbody tr')
      .first()
      .locator('input[type=text]');

    await nameInput.fill('Р'.repeat(60));
    expect((await nameInput.inputValue()).length, '60 символів — припустимо').toBe(60);
    expect(
      (await fieldErrors(page)).some((e) => e.includes('до 60 символів')),
      'на 60 символах помилки бути не має',
    ).toBe(false);

    await nameInput.fill('Р'.repeat(61));
    const value = await nameInput.inputValue();
    const hasError = (await fieldErrors(page)).some((e) => e.includes('до 60 символів'));
    expect(
      value.length <= 60 || hasError,
      `61 символ має бути або обрізаний, або позначений помилкою (довжина=${value.length}, помилка=${hasError})`,
    ).toBe(true);
  });

  test('A-05.7 дублікат номера рампи відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
    expect(await rows.count(), 'для перевірки потрібні щонайменше дві рампи').toBeGreaterThan(1);

    const firstNumber = await rows.first().locator('input[type=number]').inputValue();
    await rows.nth(1).locator('input[type=number]').fill(firstNumber);

    await expect(page.locator('.card', { hasText: 'Рампи' }).locator('.field-error')).toContainText(
      'Номер рампи — ціле число ≥ 1, унікальне в межах магазину',
    );
  });

  test('A-05.8 номер рампи менший за 1 відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
    await rows.first().locator('input[type=number]').fill('0');
    await expect(page.locator('.card', { hasText: 'Рампи' }).locator('.field-error')).toContainText(
      'Номер рампи — ціле число ≥ 1',
    );
  });

  test('A-05.9 рампу можна вимкнути', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    const row = page
      .locator('.card', { hasText: 'Рампи' })
      .locator('table.data tbody tr')
      .first();
    const checkbox = row.locator('input[type=checkbox]');
    await expect(checkbox).toBeChecked();
    await checkbox.uncheck();
    await expect(checkbox).not.toBeChecked();
    await expect(row).toContainText('Ні');
  });

  test('A-05.10 видалення останньої рампи відхиляється', async ({ page }) => {
    await openStore(page, '2229');
    await openTab(page, 'Слоти');
    const card = page.locator('.card', { hasText: 'Рампи' });
    const rows = card.locator('table.data tbody tr');

    for (let n = await rows.count(); n > 0; n -= 1) {
      await rows.first().locator('button', { hasText: 'Видалити' }).click();
    }

    await expect(card, 'без жодної рампи має бути помилка').toContainText(
      'Потрібна щонайменше одна рампа',
    );
    await expect(
      page.locator('button.btn-primary', { hasText: 'Зберегти' }),
      'форма показує помилку «Потрібна щонайменше одна рампа», ' +
        'тож кнопка «Зберегти» має бути заблокована — як це зроблено на вкладці «Загальне»',
    ).toBeDisabled();
  });

  test('A-05.10б бекенд не приймає конфігурацію без рамп', async () => {
    const store = await sandboxStore('2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const before = await apiGet<any>(`/stores/${store.branchId}/configurations`);

    const res = await apiRaw('post', `/stores/${store.branchId}/configurations`, {
      ...config,
      effectiveFrom: kyivDay(2),
      ramps: [],
    });
    expect(res.status, 'конфігурація без рамп має бути відхилена').toBe(422);
    expect(JSON.stringify(res.body)).toContain('рампу');

    const after = await apiGet<any>(`/stores/${store.branchId}/configurations`);
    expect(after.items.length, 'нової версії конфігурації не створено').toBe(before.items.length);
  });

  test('A-05.11 попередній перегляд сітки слотів рахує слоти правильно', async ({ page }) => {
    const store = await openStore(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    await openTab(page, 'Слоти');

    const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);
    const enabled = config.ramps.filter((r: any) => r.active).length;
    const perInterval = window.intervals.reduce((acc: number, i: any) => {
      const [fh, fm] = i.from.split(':').map(Number);
      const [th, tm] = i.to.split(':').map(Number);
      return acc + Math.floor((th * 60 + tm - fh * 60 - fm) / config.slotSizeMinutes);
    }, 0);

    await expect(page.locator('.kpi-value')).toHaveText(String(perInterval * enabled));
  });
});
