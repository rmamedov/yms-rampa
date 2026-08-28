/**
 * Знімки модуля магазину на всіх характерних ширинах.
 *
 * Навіщо окремо від тестів: частину дефектів верстки перевірка на
 * горизонтальний скрол не бачить у принципі. Так було з боковою панеллю, яка
 * на телефоні поверталася в потік і займала цілий екран над шапкою: скролу
 * не було, усі перевірки зелені — а екран зламаний. Тому після змін верстки
 * знімки треба саме подивитися.
 *
 * Запуск:
 *   YMS_ADMIN_PASSWORD='...' node screenshots.mjs
 *
 * Куди кладе: OUT (за замовчуванням ./screenshots).
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const IP = process.env.YMS_IP ?? '104.248.132.130';
const OUT = process.env.OUT ?? './screenshots';
const EMAIL = process.env.YMS_ADMIN_EMAIL ?? 'admin@rampa.ua';
const PASSWORD = process.env.YMS_ADMIN_PASSWORD;

if (!PASSWORD) {
  console.error('Не задано YMS_ADMIN_PASSWORD — див. .env.example у корені репозиторію.');
  process.exit(2);
}

/** Філія, у якій є бронювання: на порожньому дні дивитися нема на що. */
const STORE_QUERY = process.env.STORE_QUERY ?? '1995';

mkdirSync(OUT, { recursive: true });
const browser = await chromium.launch();

async function openBoard(width, height) {
  const page = await browser.newPage({
    ignoreHTTPSErrors: true,
    locale: 'uk-UA',
    viewport: { width, height },
  });
  await page.goto(`https://store.${IP}.sslip.io/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/auth/login')),
    page.click('button[type=submit]'),
  ]);
  await page.waitForTimeout(2500);

  const trigger = page.locator('.picker__trigger');
  if (await trigger.count()) {
    await trigger.click();
    await page.locator('.picker__search').fill(STORE_QUERY);
    await page.locator('.picker__option').first().click();
    await page.waitForTimeout(2500);
  }
  return page;
}

// Ширини: телефон, планшет, межа карткового вигляду, робочий екран.
for (const [name, width, height] of [
  ['mobile', 375, 812],
  ['tablet', 900, 1000],
  ['edge-1023', 1023, 900],
  ['desktop', 1440, 900],
]) {
  const page = await openBoard(width, height);
  const hScroll = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );
  console.log(`${name.padEnd(10)} ${width}px: горизонтальний скрол = ${hScroll}`);
  await page.screenshot({ path: `${OUT}/store-${name}.png`, fullPage: true });
  await page.close();
}

// Окремо — картка прибуття: саме в ній живуть усі дії приймальника.
const page = await openBoard(1440, 900);
const more = page.locator('.listtable__more').first();
if (await more.count()) {
  await more.click();
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${OUT}/store-card.png` });
  console.log('картка прибуття: знято');
} else {
  console.log('картка прибуття: на цю дату немає рядків, знімок пропущено');
}
await page.close();

await browser.close();
