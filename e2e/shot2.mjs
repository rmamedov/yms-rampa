import { chromium } from '@playwright/test';
const IP='104.248.132.130';
const OUT='/private/tmp/claude-501/-Users-ruslanmamedov-Desktop-Claude/d738a2d3-95d6-4ef4-b438-8dffb4db1687/scratchpad';
const b = await chromium.launch();
async function open(width, height) {
  const p = await b.newPage({ ignoreHTTPSErrors:true, locale:'uk-UA', viewport:{width,height} });
  await p.goto(`https://store.${IP}.sslip.io/login`);
  await p.fill('#email','admin@rampa.ua'); await p.fill('#password', process.env.YMS_ADMIN_PASSWORD);
  await Promise.all([p.waitForResponse(r=>r.url().includes('/auth/login')), p.click('button[type=submit]')]);
  await p.waitForTimeout(2500);
  const trig = p.locator('.picker__trigger');
  if (await trig.count()) { await trig.click(); await p.locator('.picker__search').fill('1995'); await p.locator('.picker__option').first().click(); await p.waitForTimeout(2500); }
  return p;
}
// планшет
const t = await open(900, 1000);
console.log('планшет 900px: горизонтальний скрол =', await t.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth));
await t.screenshot({ path: `${OUT}/store-tablet.png`, fullPage: true });
await t.close();
// картка з діями
const d = await open(1440, 900);
await d.locator('.listtable__more').first().click();
await d.waitForTimeout(600);
await d.screenshot({ path: `${OUT}/store-modal.png` });
console.log('модалка відкрита:', await d.locator('.modal').count());
await d.close();
await b.close();
