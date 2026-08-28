/**
 * A-01. Вхід і навігація адмін-панелі + наскрізні перевірки X-07, X-09, X-10.
 */
import { expect, test } from '@playwright/test';
import { CREDS } from '../support/env';
import {
  ADMIN,
  bodyText,
  goto,
  loginAdmin,
  untranslated,
} from '../support/admin';

test.describe('A-01 Вхід і навігація', () => {
  test('A-01.1 валідні дані ведуть на список магазинів', async ({ page }) => {
    await loginAdmin(page);
    expect(page.url(), 'після входу має відкритись розділ за замовчуванням').toContain('/stores');
    await expect(page.locator('h1')).toHaveText('Магазини');
    await expect(page.locator('.sidebar-user')).not.toBeEmpty();
  });

  test('A-01.2 невалідний пароль — повідомлення, вхід не відбувається', async ({ page }) => {
    await page.goto(`${ADMIN}/login`);
    await page.waitForSelector('#password');
    await page.locator('#email').fill(CREDS.admin.email);
    await page.locator('#password').fill('ЦеТочноНеПароль123');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/auth/login')),
      page.locator('button[type=submit]').click(),
    ]);

    const notice = page.locator('.notice-danger');
    await expect(notice, 'помилка входу має бути видимою').toBeVisible();
    await expect(notice).toContainText('Невірний e-mail або пароль');
    expect(page.url(), 'користувач лишається на сторінці входу').toContain('/login');
  });

  test('A-01.3 порожні поля — зрозуміла відмова без запиту на сервер', async ({ page }) => {
    await page.goto(`${ADMIN}/login`);
    await page.waitForSelector('#password');
    await page.locator('#email').fill('');
    await page.locator('#password').fill('');
    await page.locator('button[type=submit]').click();
    await expect(page.locator('.notice-danger')).toContainText('Заповніть e-mail і пароль');
  });

  test('A-01.4 X-09 прямий перехід без токена веде на вхід', async ({ page }) => {
    for (const path of ['/stores', '/suppliers', '/mcp-sync', '/analytics']) {
      await page.goto(ADMIN + path);
      await page.waitForURL(/\/login/, { timeout: 15_000 });
      expect(page.url(), `${path} без токена має вести на /login`).toContain('/login');
      expect(page.url(), 'адреса, куди хотів потрапити користувач, має зберігатись').toContain(
        'redirect',
      );
    }
  });

  test('A-01.5 усі пункти меню відкриваються', async ({ page }) => {
    await loginAdmin(page);

    // Перелік розділів super_admin — за матрицею 4.4: усе, на що він має право.
    // «Користувачі» (users.manage.staff) і «Журнал аудиту» (audit.read) —
    // рівноправні розділи, а не додатки до чотирьох попередніх.
    const expected = [
      { label: 'Магазини', url: '/stores', heading: 'Магазини' },
      { label: 'Постачальники', url: '/suppliers', heading: 'Постачальники' },
      { label: 'Користувачі', url: '/users', heading: 'Користувачі' },
      { label: 'Синхронізація MCP', url: '/mcp-sync', heading: 'Синхронізація MCP' },
      { label: 'Аналітика', url: '/analytics', heading: 'Аналітика' },
      { label: 'Журнал аудиту', url: '/audit', heading: 'Журнал аудиту' },
    ];

    const links = await page.locator('.sidebar-link').allInnerTexts();
    expect(links.map((s) => s.trim()), 'меню super_admin має містити всі розділи').toEqual(
      expected.map((e) => e.label),
    );

    for (const item of expected) {
      await page.locator('.sidebar-link', { hasText: item.label }).click();
      await page.waitForURL(new RegExp(item.url.replace('/', '\\/')), { timeout: 15_000 });
      await page.waitForLoadState('networkidle');
      await expect(page.locator('h1'), `розділ ${item.label} має відкритись`).toHaveText(
        item.heading,
      );
    }
  });

  test('A-01.6 вихід завершує сесію', async ({ page }) => {
    await loginAdmin(page);
    await page.locator('.sidebar-footer button', { hasText: 'Вийти' }).click();
    await page.waitForURL(/\/login/, { timeout: 15_000 });

    await page.goto(`${ADMIN}/stores`);
    await page.waitForURL(/\/login/, { timeout: 15_000 });
    expect(page.url(), 'після виходу захищені розділи недоступні').toContain('/login');
  });

  test('A-01.7 X-07 інтерфейс українською, без неперекладених ключів', async ({ page }) => {
    await loginAdmin(page);
    const found: Record<string, string[]> = {};
    for (const path of ['/stores', '/suppliers', '/mcp-sync', '/analytics']) {
      await goto(page, path);
      const leftovers = untranslated(await bodyText(page));
      if (leftovers.length > 0) {
        found[path] = leftovers;
      }
    }
    expect(found, `неперекладені ключі: ${JSON.stringify(found)}`).toEqual({});
  });

  test('A-01.8 X-10 адаптивність 360 / 768 / 1280 без горизонтального скролу', async ({ page }) => {
    await loginAdmin(page);
    const broken: string[] = [];
    for (const width of [360, 768, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      for (const path of ['/stores', '/suppliers', '/mcp-sync', '/analytics']) {
        await goto(page, path);
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        if (overflow > 1) {
          broken.push(`${path} @${width}px: зайвих ${overflow}px`);
        }
      }
    }
    expect(broken, `горизонтальний скрол: ${broken.join('; ')}`).toEqual([]);
  });
});
