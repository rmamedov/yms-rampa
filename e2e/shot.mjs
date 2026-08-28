import { chromium } from '@playwright/test';
const IP = '104.248.132.130';
const OUT = process.env.OUT || '/private/tmp/claude-501/-Users-ruslanmamedov-Desktop-Claude/d738a2d3-95d6-4ef4-b438-8dffb4db1687/scratchpad';
const b = await chromium.launch();
for (const [name, width, height] of [['desktop', 1440, 900], ['mobile', 375, 812]]) {
  const p = await b.newPage({ ignoreHTTPSErrors: true, locale: 'uk-UA', viewport: { width, height } });
  await p.goto(`https://store.${IP}.sslip.io/login`);
  await p.fill('#email', 'admin@rampa.ua');
  await p.fill('#password', process.env.YMS_ADMIN_PASSWORD);
  await Promise.all([p.waitForResponse(r => r.url().includes('/auth/login')), p.click('button[type=submit]')]);
  await p.waitForTimeout(2500);
  // Обираємо філію, у якій є бронювання, — інакше знімок показує порожній день.
  const trigger = p.locator('.picker__trigger');
  if (await trigger.count()) {
    await trigger.click();
    await p.locator('.picker__search').fill('1995');
    await p.locator('.picker__option').first().click();
    await p.waitForTimeout(2500);
  }
  const hScroll = await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  console.log(`${name} ${width}px: горизонтальний скрол = ${hScroll}`);
  await p.screenshot({ path: `${OUT}/store-${name}.png`, fullPage: true });
  await p.close();
}
await b.close();
