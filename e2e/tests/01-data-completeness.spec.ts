/**
 * Повнота даних в інтерфейсі (перевірки X-01, X-02 з плану).
 *
 * Головне правило: очікуване значення береться з API, а не з голови автора.
 * Інакше тест закріпить рівно той зріз даних, який показує інтерфейс, і
 * пропустить саме той дефект, заради якого писався.
 */
import { expect, test } from '@playwright/test';
import { adminToken, api, CREDS, HOSTS, loginUi, pageText, supplierToken } from '../support/env';

test.describe('Повнота даних', () => {
  test('X-01 адмінка: список магазинів показує всі 455 філій', async ({ page }) => {
    const ctx = await api();
    const token = await adminToken(ctx);
    const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?perPage=20`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const expected = (await res.json()).total as number;

    await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
    await page.goto(HOSTS.admin + '/stores');
    await page.waitForLoadState('networkidle');

    const text = await pageText(page);
    expect(text, 'на сторінці має бути видно загальну кількість магазинів').toContain(String(expected));
  });

  test('X-01/X-02 адмінка: вибір філій постачальника бачить УСІ київські філії', async ({ page }) => {
    const ctx = await api();
    const token = await adminToken(ctx);

    const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?city=${encodeURIComponent('Київ')}&perPage=100`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const kyivTotal = (await res.json()).total as number;
    expect(kyivTotal, 'у Києві має бути більше 20 філій, інакше перевірка безсила').toBeGreaterThan(20);

    // Беремо контрольну філію з ДРУГОЇ сторінки київського списку: саме такі
    // раніше були недосяжні, бо форма вантажила лише першу сторінку довідника.
    const far = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?city=${encodeURIComponent('Київ')}&perPage=100&page=2`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const farItems = (await far.json()).items as { externalId: string }[];
    expect(farItems.length, 'потрібна друга сторінка київських філій').toBeGreaterThan(0);
    const control = farItems[farItems.length - 1].externalId;

    // Беремо саме демо-постачальника, а не «першого-ліпшого»: склад довідника
    // весь час змінюють паралельні перевірки, і випадковий контрагент міг
    // виявитися призупиненим — тоді керування доступом недоступне, і тест
    // падав би не через дефект, а через вибір даних.
    const suppliers = await ctx.get(`${HOSTS.admin}/api/admin/v1/suppliers?limit=200`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const list = (await suppliers.json()).items as { id: string; edrpou?: string; status?: string }[];
    const supplier =
      list.find((s) => s.edrpou === '32456789') ?? list.find((s) => s.status === 'active');
    expect(supplier, 'потрібен активний постачальник для перевірки').toBeTruthy();
    const supplierId = supplier!.id;

    await loginUi(page, HOSTS.admin, {
      'input[type=email]': CREDS.admin.email,
      'input[type=password]': CREDS.admin.password,
    });
    // Довідник філій вантажиться кількома сторінками по 100 записів, і кожна
    // відповідь перемальовує віджет вибору. Рахуємо ці відповіді, щоб чекати
    // саме на завершення завантаження, а не на випадковий таймаут.
    let storePages = 0;
    let lastStorePageAt = Date.now();
    page.on('response', (r) => {
      if (r.url().includes('/api/admin/v1/stores?')) {
        storePages += 1;
        lastStorePageAt = Date.now();
      }
    });

    await page.goto(`${HOSTS.admin}/suppliers/${supplierId}`);

    // Вкладка зʼявляється лише після завантаження картки постачальника.
    const storesTab = page.locator('button:has-text("Магазини")');
    await expect(storesTab).toBeVisible();
    await storesTab.click();

    // Режим «Перелік магазинів» — саме він показує вибір філій.
    const mode = page.locator('#access-mode');
    await expect(mode).toBeVisible();
    await mode.selectOption('whitelist');

    // Довідник вантажиться кількома сторінками по 100 записів, і панель,
    // відкрита до їх завершення, лишається порожньою. Тому відкриваємо з
    // повтором, доки в ній не зʼявляться варіанти, — це чекання на дані,
    // а не послаблення перевірки.
    // Чекаємо, доки сторінки довідника перестануть надходити: інакше клік
    // потрапляє в елемент, який саме зараз замінює перемальовування.
    await expect
      .poll(() => (storePages > 0 && Date.now() - lastStorePageAt > 1000 ? 'готово' : 'вантажиться'), {
        timeout: 30_000,
      })
      .toBe('готово');

    const trigger = page.locator('app-multi-select button').first();
    await expect(trigger, 'у режимі whitelist має зʼявитися вибір філій').toBeVisible();
    const options = page.locator('app-multi-select label');

    await expect(async () => {
      if ((await options.count()) === 0) {
        await trigger.click();
      }
      expect(await options.count()).toBeGreaterThan(0);
    }).toPass({ timeout: 30_000 });

    const search = page.locator('app-multi-select input[type=search]');
    await expect(search).toBeEditable();
    await search.fill(control);

    await expect(
      page.locator('app-multi-select label', { hasText: control }),
      `контрольна філія ${control} з другої сторінки київського списку має знаходитися у виборі`,
    ).toHaveCount(1);
  });

  test('X-01 кабінет постачальника: список міст повний', async ({ page }) => {
    const ctx = await api();
    const token = await supplierToken(ctx);
    const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/cities`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const cities = (await res.json()).items as { city: string; storeCount: number }[];

    await loginUi(page, HOSTS.supplier, { 'input[type=email]': CREDS.supplier.login, 'input[type=password]': CREDS.supplier.password });
    await page.goto(HOSTS.supplier + '/booking/cities');
    await page.waitForLoadState('networkidle');

    const text = await pageText(page);
    for (const c of cities) {
      expect(text, `місто ${c.city} має бути в списку`).toContain(c.city);
    }
  });

  test('X-01 кабінет постачальника: у місті видно всі активні філії', async ({ page }) => {
    const ctx = await api();
    const token = await supplierToken(ctx);
    const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Київ')}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const stores = (await res.json()).items as { externalId: string }[];

    await loginUi(page, HOSTS.supplier, { 'input[type=email]': CREDS.supplier.login, 'input[type=password]': CREDS.supplier.password });
    await page.goto(HOSTS.supplier + '/booking/cities/' + encodeURIComponent('Київ'));
    await page.waitForLoadState('networkidle');

    const text = await pageText(page);
    const missing = stores.filter((s) => !text.includes(s.externalId)).map((s) => s.externalId);
    expect(missing, `філії, яких немає на екрані: ${missing.join(', ')}`).toHaveLength(0);
  });

  test('X-02 адмінка: пошук магазину з «дальньої» сторінки знаходить його', async ({ page }) => {
    const ctx = await api();
    const token = await adminToken(ctx);

    // Беремо філію з кінця повного списку — вона свідомо не на першій сторінці.
    const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?perPage=100&page=4`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const items = (await res.json()).items as { externalId: string; address: string }[];
    const target = items.find((i) => i.address && i.externalId);
    test.skip(!target, 'немає даних для перевірки');

    await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
    await page.goto(HOSTS.admin + '/stores');
    await page.waitForLoadState('networkidle');

    // Фільтр застосовується кнопкою — набраний текст сам собою нічого не шукає.
    const search = page.locator('#store-search');
    await search.fill(target!.externalId);
    await page.locator('button:has-text("Застосувати")').click();
    await page.waitForResponse((r) => r.url().includes('/stores?') && r.status() === 200);
    await expect(page.locator('table')).toContainText(target!.externalId);

    const text = await pageText(page);
    expect(text, `пошук за externalId ${target!.externalId} має знайти філію`).toContain(target!.externalId);
  });
});
