import { chromium, request } from '@playwright/test';

const IP = '104.248.132.130';
const H = { supplier: `https://yms.${IP}.sslip.io`, store: `https://store.${IP}.sslip.io` };
const CRED = { email: 'admin@rampa.ua', password: '${YMS_ADMIN_PASSWORD}' };

const ctx = await request.newContext({ ignoreHTTPSErrors: true });
const supTok = (await (await ctx.post(`${H.supplier}/api/supplier/v1/auth/login`, { data: { login: 'supplier@rampa.ua', password: '${YMS_SUPPLIER_PASSWORD}' } })).json()).accessToken;
const cat = await (await ctx.get(`${H.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`, { headers: { Authorization: 'Bearer ' + supTok } })).json();
const sandbox = cat.items.filter((s) => ['2226', '2227', '2229', '2230'].includes(s.externalId));
const primary = sandbox.filter((s) => s.ramps.filter((r) => r.active !== false).length > 1).sort((a, b) => b.ramps.length - a.ramps.length)[0];
console.log('primary:', primary.externalId, primary.storeId, 'ramps:', JSON.stringify(primary.ramps));

const b = await chromium.launch();
const page = await b.newPage({ ignoreHTTPSErrors: true, locale: 'uk-UA', timezoneId: 'Europe/Kyiv', viewport: { width: 1280, height: 900 } });
page.on('console', (m) => console.log(`  [console:${m.type()}] ${m.text().slice(0, 200)}`));
page.on('requestfailed', (r) => console.log(`  [reqfail] ${r.url()} ${r.failure()?.errorText}`));
page.on('response', (r) => {
  const u = r.url();
  if (u.includes('/api/store/v1')) console.log(`  [net] ${r.status()} ${u.replace(H.store, '')}`);
});

await page.goto(H.store + '/');
await page.waitForSelector('input[type=password]');
await page.locator('#email').fill(CRED.email);
await page.locator('#password').fill(CRED.password);
await Promise.all([page.waitForResponse((r) => r.url().includes('/auth/login')), page.locator('button[type=submit]').click()]);
await page.waitForFunction(() => !location.pathname.includes('/login'));
await page.waitForLoadState('networkidle');
console.log('\n--- after login, url:', page.url());
console.log('select value:', await page.locator('.appbar__select').inputValue());
console.log('localStorage selected:', await page.evaluate(() => localStorage.getItem('yms.store.selectedStoreId')));
console.log('cards:', await page.locator('article.bcard').count());

console.log('\n=== selectOption to primary ===');
await page.locator('.appbar__select').selectOption(primary.storeId);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1500);
console.log('select value:', await page.locator('.appbar__select').inputValue());
console.log('localStorage selected:', await page.evaluate(() => localStorage.getItem('yms.store.selectedStoreId')));
console.log('board cards:', await page.locator('.board__col article.bcard').count());
console.log('stats:', (await page.locator('.stats').innerText().catch(() => '')).replace(/\s+/g, ' '));
console.log('loading visible:', await page.locator('text=Завантаження…').count());

console.log('\n=== open walk-in ===');
await page.getByRole('button', { name: 'Позапланове прибуття' }).click();
await page.waitForTimeout(2500);
const dlg = page.locator('[role=dialog]');
console.log('supplier options:', await page.locator('#wi-supplier option').count());
console.log('slot options:', await page.locator('#wi-slot option').count());
console.log('form-errors:', JSON.stringify(await dlg.locator('.form-error').allInnerTexts()));
await dlg.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
await page.waitForTimeout(800);
console.log('after submit form-errors:', JSON.stringify(await dlg.locator('.form-error').allInnerTexts()));
console.log('\ndialog html head:\n', (await dlg.innerHTML()).slice(0, 2500));

await b.close();
await ctx.dispose();
