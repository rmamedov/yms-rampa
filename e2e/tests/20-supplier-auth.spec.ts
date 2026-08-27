/**
 * S-01. Вхід у кабінет постачальника, блокування після невдалих спроб,
 * вихід і захист сторінок (X-09).
 *
 * Блокування перевіряється на СЛУЖБОВОМУ логіні `uitest.lock…@rampa.test`:
 * лічильник невдалих спроб у бекенді ведеться за логіном (LoginThrottle,
 * 5 спроб / 15 хв), і для неіснуючого логіна поведінка навмисно та сама.
 * Заблокувати справжній supplier@rampa.ua означало б на 15 хвилин зупинити
 * решту перевірок стенду.
 */
import { expect, test } from '@playwright/test';
import { CREDS, HOSTS } from '../support/env';
import { bodyText, goto, languageProblems, loginSupplier, stamp } from '../support/supplier';

async function attempt(page: import('@playwright/test').Page, email: string, password: string) {
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(password);
  const [response] = await Promise.all([
    page
      .waitForResponse((r) => r.url().includes('/auth/login') && r.request().method() === 'POST', { timeout: 20_000 })
      .catch(() => null),
    page.locator('button[type=submit]').click(),
  ]);
  await page.waitForTimeout(300);
  return response;
}

test.describe('S-01 Вхід і сесія', () => {
  test('S-01.1 валідні дані відкривають головну, у шапці — логін і роль', async ({ page }) => {
    await loginSupplier(page);
    await expect(page).toHaveURL(/\/home$/);

    const text = await bodyText(page);
    expect(text, 'у бічній панелі має бути логін користувача').toContain(CREDS.supplier.login);
    expect(text, 'роль supplier_admin має бути підписана українською').toContain('Адміністратор постачальника');
    expect(text).toContain('Мої найближчі поставки');
  });

  test('S-01.2 невірний пароль — зрозуміле повідомлення, лишаємось на /login', async ({ page }) => {
    await page.goto(HOSTS.supplier + '/');
    await page.waitForSelector('#login-password');

    await attempt(page, CREDS.supplier.login, 'Zовсім#Не2026Пароль');

    await expect(page.locator('.login__error')).toHaveText('Невірний логін або пароль');
    await expect(page).toHaveURL(/\/login/);

    // Успішний вхід одразу скидає лічильник невдалих спроб цього логіна,
    // щоб перевірка не заважала решті тестів.
    await attempt(page, CREDS.supplier.login, CREDS.supplier.password);
    await page.waitForFunction(() => !location.pathname.includes('/login'), undefined, { timeout: 30_000 });
  });

  test('S-01.3 порожні поля не відправляють форму', async ({ page }) => {
    await page.goto(HOSTS.supplier + '/');
    await page.waitForSelector('#login-password');

    let requested = false;
    page.on('request', (r) => {
      if (r.url().includes('/auth/login')) {
        requested = true;
      }
    });

    await page.locator('button[type=submit]').click();
    await page.waitForTimeout(500);

    await expect(page.locator('.login__error')).toBeVisible();
    expect(requested, 'порожня форма не має йти на сервер').toBe(false);

    // Лише пароль без e-mail — так само відмова.
    await page.locator('#login-password').fill(CREDS.supplier.password);
    await page.locator('button[type=submit]').click();
    await page.waitForTimeout(500);
    await expect(page.locator('.login__error')).toBeVisible();
    expect(requested).toBe(false);
  });

  test('S-01.4 телефон у полі e-mail веде до застосунку водія', async ({ page }) => {
    await page.goto(HOSTS.supplier + '/');
    await page.waitForSelector('#login-password');

    await page.locator('#login-email').fill('+380671234567');
    await page.locator('#login-password').fill('будь-що');
    await page.locator('button[type=submit]').click();
    await page.waitForTimeout(400);

    await expect(page.locator('.login__error')).toContainText('застосунком водія');
    await expect(page.locator('a[href="/driver"]')).toBeVisible();
  });

  test('S-01.5 блокування після 5 невдалих спроб', async ({ page }) => {
    test.setTimeout(120_000);
    const login = `uitest.lock.${stamp()}@rampa.test`;

    await page.goto(HOSTS.supplier + '/');
    await page.waitForSelector('#login-password');

    for (let i = 1; i <= 5; i++) {
      const response = await attempt(page, login, `невірний-${i}`);
      expect(response?.status(), `спроба ${i} має отримати 401`).toBe(401);
      await expect(page.locator('.login__error'), `спроба ${i}: текст помилки`).toHaveText(
        'Невірний логін або пароль',
      );
    }

    const locked = await attempt(page, login, 'невірний-6');
    expect(locked?.status(), 'після 5 невдалих спроб вхід має бути заблокований (423/429)').not.toBe(401);
    await expect(page.locator('.login__error'), 'користувач має бачити, що спроби вичерпано').toHaveText(
      'Забагато спроб. Спробуйте пізніше',
    );
  });

  test('S-01.6 вихід завершує сесію, захищені сторінки повертають на /login', async ({ page }) => {
    await loginSupplier(page);

    await page.locator('button:has-text("Вийти")').click();
    await page.waitForURL(/\/login/, { timeout: 20_000 });

    // Прямий перехід за URL без токена (X-09).
    for (const path of ['/home', '/vehicles', '/drivers', '/route-sheets', '/booking/cities']) {
      await page.goto(HOSTS.supplier + path);
      await page.waitForLoadState('networkidle');
      await expect(page, `${path} без сесії має вести на /login`).toHaveURL(/\/login/);
    }
  });

  test('S-01.7 X-07 екран входу без англійських рядків і ключів перекладу', async ({ page }) => {
    await page.goto(HOSTS.supplier + '/');
    await page.waitForSelector('#login-password');

    const problems = languageProblems(await bodyText(page));
    expect(problems, `неперекладені фрагменти: ${problems.join(', ')}`).toHaveLength(0);
  });

  test('S-01.8 X-10 екран входу на 360 px без горизонтального скролу', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto(HOSTS.supplier + '/');
    await page.waitForSelector('#login-password');

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow, 'сторінка входу не має скролитись вбік').toBeLessThanOrEqual(1);
  });

  test('S-01.9 сесія переживає перезавантаження сторінки', async ({ page }) => {
    await loginSupplier(page);
    await goto(page, '/vehicles');
    await page.reload();
    await page.waitForLoadState('networkidle');
    await expect(page, 'після F5 користувач має лишитись у кабінеті').toHaveURL(/\/vehicles/);
  });
});
