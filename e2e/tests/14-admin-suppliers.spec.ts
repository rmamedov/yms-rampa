/**
 * A-10. Постачальники: список, пошук, створення з валідацією, доступ до магазинів,
 * редагування, призупинення й поновлення.
 *
 * Тестові дані: назва `UITEST-Постачальник-<мітка>`, ЄДРПОУ 99000000–99009999.
 * Кожен створений постачальник реєструється через registerArtifact().
 */
import { expect, request, test } from '@playwright/test';
import {
  apiGet,
  apiRaw,
  apiStores,
  apiSuppliers,
  dataRowCount,
  fieldErrors,
  goto,
  loginAdmin,
  multiSelectOptions,
  multiSelectSearch,
  nextTestEdrpou,
  openTab,
  paginationTotal,
  testSupplierName,
  track,
  waitForToast,
} from '../support/admin';
import { HOSTS } from '../support/env';

test.beforeEach(async ({ page }) => {
  await loginAdmin(page);
});

/** Заповнює форму «Загальне» картки постачальника. */
async function fillSupplierForm(
  page: import('@playwright/test').Page,
  data: { name?: string; edrpou?: string; contact?: string; phone?: string; email?: string },
): Promise<void> {
  if (data.name !== undefined) await page.locator('#sup-name').fill(data.name);
  if (data.edrpou !== undefined) await page.locator('#sup-edrpou').fill(data.edrpou);
  if (data.contact !== undefined) await page.locator('#sup-person').fill(data.contact);
  if (data.phone !== undefined) await page.locator('#sup-phone').fill(data.phone);
  if (data.email !== undefined) await page.locator('#sup-email').fill(data.email);
}

/** Створює постачальника через UI і повертає його id з бекенду. */
async function createSupplier(
  page: import('@playwright/test').Page,
  data: { name: string; edrpou: string; contact: string; phone: string; email: string },
): Promise<string> {
  await goto(page, '/suppliers/new');
  await fillSupplierForm(page, data);
  await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
  await page.waitForURL(/\/suppliers\/(?!new)[0-9a-f-]{8}/, { timeout: 20_000 });
  const id = page.url().split('/suppliers/')[1].split('?')[0];
  track('supplier', id, data.name);
  return id;
}

test.describe('A-10 Постачальники', () => {
  test('A-10.1 X-01 список показує стільки ж, скільки в API', async ({ page }) => {
    const expected = await apiSuppliers('limit=20&offset=0');
    await goto(page, '/suppliers');

    await expect
      .poll(() => paginationTotal(page), { message: 'лічильник «Усього»' })
      .toBe(expected.total);
    expect(await dataRowCount(page), 'рядків на першій сторінці').toBe(
      Math.min(20, expected.total),
    );
  });

  test('A-10.2 колонки списку відповідають даним API', async ({ page }) => {
    const expected = await apiSuppliers('limit=20&offset=0');
    await goto(page, '/suppliers');

    for (const supplier of expected.items) {
      const row = page.locator('table.data tbody tr', { hasText: supplier.name }).first();
      await expect(row, `рядок постачальника «${supplier.name}»`).toBeVisible();
      if (supplier.edrpou) {
        await expect(row).toContainText(supplier.edrpou);
      }
      await expect(row).toContainText(
        supplier.status === 'active' ? 'Активний' : 'Призупинений',
      );
      await expect(row).toContainText(
        supplier.storeAccess.allStores
          ? 'Усі магазини'
          : `Перелік магазинів (${supplier.storeAccess.storeIds.length})`,
      );
    }
  });

  test('A-10.3 X-02 пошук за назвою і за ЄДРПОУ', async ({ page }) => {
    const all = await apiSuppliers('limit=200&offset=0');
    const target = all.items.find((s) => s.edrpou);
    expect(target, 'потрібен постачальник із ЄДРПОУ').toBeTruthy();

    for (const term of [target!.name.slice(0, 8), target!.edrpou!]) {
      const expected = await apiSuppliers(`q=${encodeURIComponent(term)}&limit=100&offset=0`);
      await goto(page, '/suppliers');
      await page.locator('#sup-search').fill(term);
      await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
      await page.waitForLoadState('networkidle');

      await expect
        .poll(() => paginationTotal(page), { message: `пошук «${term}»` })
        .toBe(expected.total);
    }
  });

  test('A-10.4 фільтр за статусом збігається з API', async ({ page }) => {
    for (const status of ['active', 'suspended']) {
      const expected = await apiSuppliers(`status=${status}&limit=100&offset=0`);
      await goto(page, '/suppliers');
      await page.locator('#sup-status').selectOption(status);
      await page.waitForLoadState('networkidle');
      await expect
        .poll(() => paginationTotal(page), { message: `статус ${status}` })
        .toBe(expected.total);
    }
  });

  test('A-10.5 X-04 порожній результат пошуку', async ({ page }) => {
    await goto(page, '/suppliers');
    await page.locator('#sup-search').fill('ZZZ-НЕМАЄ-ТАКОГО');
    await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
    await page.waitForLoadState('networkidle');

    await expect.poll(() => dataRowCount(page)).toBe(0);
    await expect(page.locator('app-empty-state')).toContainText(
      'Постачальників за заданими умовами не знайдено',
    );
  });

  test('A-10.6 X-05 ЄДРПОУ з 9 цифр відхиляється, 8 і 10 приймаються', async ({ page }) => {
    await goto(page, '/suppliers/new');
    await fillSupplierForm(page, { name: 'UITEST перевірка ЄДРПОУ', contact: 'Тест' });

    await page.locator('#sup-edrpou').fill('123456789');
    await expect(page.locator('.field-error'), 'дев’ять цифр — помилка').toContainText(
      'Код ЄДРПОУ — 8 або 10 цифр',
    );
    await expect(page.locator('button.btn-primary', { hasText: 'Зберегти' })).toBeDisabled();

    for (const valid of ['12345678', '1234567890']) {
      await page.locator('#sup-edrpou').fill(valid);
      expect(
        (await fieldErrors(page)).filter((e) => e.includes('ЄДРПОУ')),
        `${valid.length} цифр має прийматись`,
      ).toEqual([]);
    }

    await page.locator('#sup-edrpou').fill('1234abcd');
    await expect(page.locator('.field-error'), 'літери в ЄДРПОУ — помилка').toContainText(
      'Код ЄДРПОУ — 8 або 10 цифр',
    );
  });

  test('A-10.7 X-05 назва обовʼязкова, контактна особа обовʼязкова', async ({ page }) => {
    await goto(page, '/suppliers/new');
    await fillSupplierForm(page, { name: '', contact: '' });
    const errors = await fieldErrors(page);
    expect(errors, 'обидві помилки видимі').toEqual(
      expect.arrayContaining([
        expect.stringContaining('Назва обовʼязкова'),
        expect.stringContaining('Імʼя контактної особи обовʼязкове'),
      ]),
    );
    await expect(page.locator('button.btn-primary', { hasText: 'Зберегти' })).toBeDisabled();
  });

  test('A-10.8 X-05 e-mail і телефон валідуються', async ({ page }) => {
    await goto(page, '/suppliers/new');
    await fillSupplierForm(page, { name: 'UITEST контакти', contact: 'Тест' });

    await page.locator('#sup-email').fill('не-пошта');
    await expect(page.locator('.field-error')).toContainText('Невірний формат e-mail');
    await page.locator('#sup-email').fill('uitest@rampa.test');
    expect((await fieldErrors(page)).filter((e) => e.includes('e-mail'))).toEqual([]);

    await page.locator('#sup-phone').fill('0501234567');
    await expect(page.locator('.field-error')).toContainText('Телефон у форматі +380XXXXXXXXX');
    await page.locator('#sup-phone').fill('+380501234567');
    expect((await fieldErrors(page)).filter((e) => e.includes('Телефон'))).toEqual([]);
  });

  /**
   * Доступ у кабінет можна видати одразу при створенні контрагента.
   * Перевіряємо не лише форму, а й головне: цим логіном справді входять.
   */
  test('A-10.30 постачальника можна створити разом із логіном і паролем', async ({ page }) => {
    const name = testSupplierName('доступ');
    const login = `uitest.${Date.now().toString(36)}@rampa.ua`;
    const password = 'Nadiyn1yParol';

    await goto(page, '/suppliers/new');
    await fillSupplierForm(page, {
      name,
      edrpou: nextTestEdrpou(),
      contact: 'Ірина Тест',
      phone: '+380501112233',
      email: 'kontakt@rampa.ua',
    });
    await page.locator('#sup-login').fill(login);
    await page.locator('#sup-password').fill(password);
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await page.waitForURL(/\/suppliers\/(?!new)[0-9a-f-]{8}/, { timeout: 20_000 });

    const id = page.url().split('/suppliers/')[1].split('?')[0];
    track('supplier', id, name);

    // Пароль показується один раз — модалкою одразу після створення.
    await expect(page.locator('#issued-login')).toHaveValue(login);
    await expect(page.locator('#issued-password')).toHaveValue(password);

    // І цим доступом справді можна увійти в кабінет постачальника.
    const ctx = await request.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/auth/login`, {
      data: { login, password },
    });
    expect(res.status(), 'виданим доступом має відкриватися кабінет').toBe(200);
    await ctx.dispose();
  });

  test('A-10.31 логін не у форматі пошти відхиляється', async ({ page }) => {
    await goto(page, '/suppliers/new');
    await page.locator('#sup-login').fill('380501234567');
    await expect(
      page.locator('.field', { has: page.locator('#sup-login') }).locator('.field-error'),
    ).toContainText('Логін має бути коректною адресою пошти');
  });

  test('A-10.32 короткий пароль відхиляється до відправки', async ({ page }) => {
    await goto(page, '/suppliers/new');
    await page.locator('#sup-password').fill('123');
    await expect(
      page.locator('.field', { has: page.locator('#sup-password') }).locator('.field-error'),
    ).toContainText('Пароль — щонайменше 10 символів');
  });

  test('A-10.9 створення постачальника з усіма полями', async ({ page }) => {
    const name = testSupplierName('створення');
    const edrpou = nextTestEdrpou();
    const id = await createSupplier(page, {
      name,
      edrpou,
      contact: 'UITEST Контактна Особа',
      phone: '+380501112233',
      email: 'uitest.supplier@rampa.test',
    });

    const saved = await apiGet<any>(`/suppliers/${id}`);
    expect(saved.name, 'назву збережено').toBe(name);
    expect(saved.edrpou, 'ЄДРПОУ збережено').toBe(edrpou);
    expect(saved.contacts[0].name).toBe('UITEST Контактна Особа');
    expect(saved.contacts[0].phone).toBe('+380501112233');
    expect(saved.contacts[0].email).toBe('uitest.supplier@rampa.test');
    expect(saved.status).toBe('active');

    // і новий постачальник видно у списку
    await goto(page, '/suppliers');
    await page.locator('#sup-search').fill(name);
    await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table.data tbody')).toContainText(name);
  });

  test('A-10.10 дублікат ЄДРПОУ відхиляється зрозумілим повідомленням', async ({ page }) => {
    const edrpou = nextTestEdrpou();
    const first = testSupplierName('дублікат-1');
    await createSupplier(page, {
      name: first,
      edrpou,
      contact: 'UITEST',
      phone: '+380501112244',
      email: 'uitest.dup1@rampa.test',
    });

    const before = await apiSuppliers('limit=200&offset=0');
    await goto(page, '/suppliers/new');
    await fillSupplierForm(page, {
      name: testSupplierName('дублікат-2'),
      edrpou,
      contact: 'UITEST',
      phone: '+380501112255',
      email: 'uitest.dup2@rampa.test',
    });
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();

    const toast = await waitForToast(page);
    expect(toast, 'повідомлення має пояснювати причину').toMatch(/ЄДРПОУ|існує/i);
    expect(page.url(), 'на картку нового постачальника не переходимо').toContain('/suppliers/new');

    const after = await apiSuppliers('limit=200&offset=0');
    expect(after.total, 'другого постачальника не створено').toBe(before.total);
  });

  test('A-10.11 дублікат назви відхиляється', async ({ page }) => {
    const name = testSupplierName('назва-дубль');
    await createSupplier(page, {
      name,
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501112266',
      email: 'uitest.name1@rampa.test',
    });

    const before = await apiSuppliers('limit=200&offset=0');
    await goto(page, '/suppliers/new');
    await fillSupplierForm(page, {
      name,
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501112277',
      email: 'uitest.name2@rampa.test',
    });
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    const toast = await waitForToast(page);
    expect(toast.length, 'відмова показана текстом').toBeGreaterThan(0);

    const after = await apiSuppliers('limit=200&offset=0');
    expect(after.total, 'постачальника з дубльованою назвою не створено').toBe(before.total);
  });

  test('A-10.12 редагування картки зберігається', async ({ page }) => {
    const id = await createSupplier(page, {
      name: testSupplierName('редагування'),
      edrpou: nextTestEdrpou(),
      contact: 'UITEST До',
      phone: '+380501113311',
      email: 'uitest.edit@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await fillSupplierForm(page, {
      contact: 'UITEST Після',
      phone: '+380501113322',
      email: 'uitest.edited@rampa.test',
    });
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);

    const saved = await apiGet<any>(`/suppliers/${id}`);
    expect(saved.contacts[0].name).toBe('UITEST Після');
    expect(saved.contacts[0].phone).toBe('+380501113322');
    expect(saved.contacts[0].email).toBe('uitest.edited@rampa.test');
  });

  test('A-10.13 призупинення і поновлення постачальника', async ({ page }) => {
    const id = await createSupplier(page, {
      name: testSupplierName('пауза'),
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501114411',
      email: 'uitest.suspend@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await page.locator('button', { hasText: 'Призупинити' }).click();
    await page.locator('#suspend-reason').fill('UITEST причина призупинення');
    await page.locator('.modal-footer button', { hasText: 'Підтвердити' }).click();
    await waitForToast(page);

    let saved = await apiGet<any>(`/suppliers/${id}`);
    expect(saved.status, 'постачальника призупинено').toBe('suspended');
    expect(saved.suspendReason, 'причину збережено').toBe('UITEST причина призупинення');

    await goto(page, `/suppliers/${id}`);
    await expect(page.locator('.notice-warn')).toContainText('UITEST причина призупинення');
    await expect(page.locator('.badge-danger')).toContainText('Призупинений');

    await page.locator('button', { hasText: 'Активувати' }).click();
    await waitForToast(page);
    saved = await apiGet<any>(`/suppliers/${id}`);
    expect(saved.status, 'постачальника поновлено').toBe('active');
  });

  test('A-10.14 призупинений постачальник зникає з фільтра «Активний»', async ({ page }) => {
    const name = testSupplierName('фільтр');
    const id = await createSupplier(page, {
      name,
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501115511',
      email: 'uitest.filter@rampa.test',
    });
    const suspend = await apiRaw('post', `/suppliers/${id}/suspend`, { reason: 'UITEST' });
    expect(suspend.status).toBeLessThan(400);

    await goto(page, '/suppliers');
    await page.locator('#sup-search').fill(name);
    await page.locator('#sup-status').selectOption('active');
    await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
    await page.waitForLoadState('networkidle');
    await expect.poll(() => dataRowCount(page), { message: 'серед активних його немає' }).toBe(0);

    await page.locator('#sup-status').selectOption('suspended');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table.data tbody')).toContainText(name);
  });

  test('A-10.15 режим доступу «усі магазини» ↔ «перелік магазинів»', async ({ page }) => {
    const id = await createSupplier(page, {
      name: testSupplierName('доступ'),
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501116611',
      email: 'uitest.access@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await openTab(page, 'Магазини');
    await expect(page.locator('#access-mode'), 'новий постачальник — «усі магазини»').toHaveValue(
      'all',
    );

    await page.locator('#access-mode').selectOption('whitelist');
    const options = await multiSelectOptions(page, 'Магазини');
    expect(options.length, 'у переліку мають бути магазини').toBeGreaterThan(0);

    // обираємо перший варіант і зберігаємо
    const root = page.locator('.multi-select').filter({
      has: page.locator('.field-label:text-is("Магазини")'),
    });
    await root.locator('.multi-select-list label').first().locator('input').check();
    await root.locator('.multi-select-footer button', { hasText: 'Закрити' }).click();
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);

    const saved = await apiGet<any>(`/suppliers/${id}`);
    expect(saved.storeAccess.allStores, 'режим змінено на whitelist').toBe(false);
    expect(saved.storeAccess.storeIds.length, 'обраний магазин збережено').toBe(1);

    // назад на «усі магазини»
    await goto(page, `/suppliers/${id}`);
    await openTab(page, 'Магазини');
    await page.locator('#access-mode').selectOption('all');
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);
    const back = await apiGet<any>(`/suppliers/${id}`);
    expect(back.storeAccess.allStores, 'повернулись до «усі магазини»').toBe(true);
  });

  test('A-10.16 X-01 у виборі магазинів доступні всі придатні філії мережі', async ({ page }) => {
    // Придатні = ті, що взагалі можна показати: з містом і адресою.
    // Записи MCP без міста застосунок свідомо ховає, тож еталон рахуємо так само.
    const all = await apiStores('perPage=100&page=1');
    const usable: number = await (async () => {
      let count = 0;
      const pages = Math.ceil(all.total / 100);
      for (let p = 1; p <= pages; p += 1) {
        const chunk = await apiStores(`perPage=100&page=${p}`);
        count += chunk.items.filter((i) => i.city?.trim() && i.address?.trim()).length;
      }
      return count;
    })();
    const total = usable;

    const id = await createSupplier(page, {
      name: testSupplierName('повнота'),
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501117711',
      email: 'uitest.full@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await openTab(page, 'Магазини');
    await page.locator('#access-mode').selectOption('whitelist');

    const options = await multiSelectOptions(page, 'Магазини');
    expect(
      options.length,
      `у виборі філій видно ${options.length} варіантів, а придатних філій у мережі ${total}: ` +
        'решту неможливо ані побачити, ані обрати без пошуку',
    ).toBeGreaterThanOrEqual(total);
  });

  test('A-10.17 X-02 пошук «Київ» у виборі магазинів знаходить усі київські філії', async ({
    page,
  }) => {
    const kyiv = await apiStores(`city=${encodeURIComponent('Київ')}&perPage=20`);
    const id = await createSupplier(page, {
      name: testSupplierName('пошук-київ'),
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501118811',
      email: 'uitest.kyiv@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await openTab(page, 'Магазини');
    await page.locator('#access-mode').selectOption('whitelist');

    const found = await multiSelectSearch(page, 'Магазини', 'Київ');
    expect(
      found.length,
      `пошук «Київ» показав ${found.length} філій, а в базі їх ${kyiv.total}`,
    ).toBeGreaterThanOrEqual(kyiv.total);
  });

  test('A-10.18 у виборі магазинів немає непридатних записів без міста й адреси', async ({
    page,
  }) => {
    const id = await createSupplier(page, {
      name: testSupplierName('сміття'),
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501119911',
      email: 'uitest.junk@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await openTab(page, 'Магазини');
    await page.locator('#access-mode').selectOption('whitelist');

    const options = await multiSelectOptions(page, 'Магазини');
    const junk = options.filter((o) => /—\s*,\s*$/.test(o.trim()) || /—\s*,$/.test(o.trim()));
    expect(
      junk,
      `у виборі є ${junk.length} записів без міста й адреси: ${junk.slice(0, 5).join(' | ')}`,
    ).toEqual([]);
  });

  test('A-10.19 форма створення дозволяє одразу задати доступ до магазинів', async ({ page }) => {
    await goto(page, '/suppliers/new');

    // Вкладка «Магазини» має бути доступна ще до першого збереження — інакше
    // новий постачальник до першого редагування має доступ за замовчуванням.
    const storesTab = page.locator('.tabs button', { hasText: 'Магазини' });
    await expect(storesTab, 'на формі створення є вкладка «Магазини»').toBeVisible();
    await openTab(page, 'Магазини');

    const mode = page.locator('#access-mode');
    await expect(mode, 'режим доступу задається одразу на створенні').toBeVisible();
    expect(
      (await mode.locator('option').allInnerTexts()).map((s) => s.trim()),
      'обидва режими доступу пропонуються ще до збереження',
    ).toEqual(['Усі магазини', expect.stringContaining('Перелік магазинів')]);

    // Whitelist працює на створенні так само, як у картці: перелік філій
    // завантажується і його можна вибирати.
    await mode.selectOption('whitelist');
    const options = await multiSelectOptions(page, 'Магазини');
    expect(options.length, 'перелік філій доступний уже на формі створення').toBeGreaterThan(0);
  });

  test('A-10.20 видалення щойно створеного постачальника без бронювань', async ({ page }) => {
    const name = testSupplierName('видалення');
    const id = await createSupplier(page, {
      name,
      edrpou: nextTestEdrpou(),
      contact: 'UITEST',
      phone: '+380501110011',
      email: 'uitest.del@rampa.test',
    });

    await goto(page, `/suppliers/${id}`);
    await page.locator('button.btn-danger', { hasText: 'Видалити' }).click();
    const toast = await waitForToast(page).catch(() => '');

    const res = await apiRaw('get', `/suppliers/${id}`);
    expect(
      res.status,
      'постачальника створено щойно, бронювань у нього бути не може, ' +
        `тож видалення має спрацювати; повідомлення на екрані: «${toast}»`,
    ).toBe(404);
  });

  test('A-10.21 видалення постачальника з бронюваннями відхиляється', async () => {
    // Бронювання створюються лише в кабінеті постачальника (/api/supplier/v1),
    // тож із адмін-панелі підготувати умову «є активні бронювання» неможливо.
    // Видаляти демо-постачальника наосліп не можна: якщо бронювань немає,
    // перевірка знищить дані стенду. Сценарій лишається непокритим свідомо.
    test.skip(
      true,
      'потрібне бронювання, яке створюється поза адмін-панеллю (кабінет постачальника, S-06)',
    );
  });
});
