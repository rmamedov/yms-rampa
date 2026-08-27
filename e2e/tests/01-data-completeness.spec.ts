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

    // Скільки київських філій насправді.
    const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?city=${encodeURIComponent('Київ')}&perPage=100`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const body = await res.json();
    const kyivTotal = body.total as number;
    expect(kyivTotal, 'у Києві має бути більше 20 філій, інакше тест безсилий').toBeGreaterThan(20);

    await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
    await page.goto(HOSTS.admin + '/suppliers/new');
    await page.waitForLoadState('networkidle');

    // Скільки варіантів філій узагалі завантажив застосунок.
    const loaded = await page.evaluate(() => {
      const w = window as unknown as { __storeOptionsCount?: number };
      return w.__storeOptionsCount ?? null;
    });

    // Головна перевірка — через пошук: набираємо «Київ» і рахуємо знайдене.
    const searchBox = page.locator('input[type=search], input[placeholder*="ошук"], input[placeholder*="ілі"]').first();
    const hasSearch = await searchBox.count();
    expect(hasSearch, 'у виборі філій має бути пошук').toBeGreaterThan(0);

    await searchBox.fill('Київ');
    await page.waitForTimeout(1200);

    const optionsShown = await page.evaluate(() => {
      const nodes = document.querySelectorAll('[role=option], .option, li, label');
      return [...nodes].map((n) => (n as HTMLElement).innerText || '').filter((t) => t.includes('Київ')).length;
    });

    expect(
      optionsShown,
      `пошук «Київ» показав ${optionsShown} філій, а в базі їх ${kyivTotal}` +
        (loaded ? ` (застосунок завантажив лише ${loaded})` : ''),
    ).toBeGreaterThanOrEqual(kyivTotal);
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

    const search = page.locator('input[type=search], input[placeholder*="ошук"]').first();
    await search.fill(target!.externalId);
    await page.waitForTimeout(1500);

    const text = await pageText(page);
    expect(text, `пошук за externalId ${target!.externalId} має знайти філію`).toContain(target!.externalId);
  });
});
