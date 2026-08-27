import { chromium, request } from '@playwright/test';

const IP = '104.248.132.130';
const H = { supplier: `https://yms.${IP}.sslip.io`, store: process.env.STORE_HOST ?? `https://store.${IP}.sslip.io` };
const CRED = { email: 'admin@rampa.ua', password: '${YMS_ADMIN_PASSWORD}' };
const key = (o = 0) => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date(Date.now() + o * 86400000));

const ctx = await request.newContext({ ignoreHTTPSErrors: true });
const supTok = (await (await ctx.post(`${H.supplier}/api/supplier/v1/auth/login`, { data: { login: 'supplier@rampa.ua', password: '${YMS_SUPPLIER_PASSWORD}' } })).json()).accessToken;
const cat = await (await ctx.get(`${H.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`, { headers: { Authorization: 'Bearer ' + supTok } })).json();
const sandbox = cat.items.filter((s) => ['2226', '2227', '2229', '2230'].includes(s.externalId));
console.log('sandbox maxWeight:', JSON.stringify(sandbox.map((s) => ({ id: s.externalId, max: s.maxVehicleWeightTons, ramps: s.ramps.length, slot: s.slotSizeMinutes }))));
const primary = sandbox.filter((s) => s.ramps.filter((r) => r.active !== false).length > 1).sort((a, b) => b.ramps.length - a.ramps.length)[0];
console.log('primary:', primary.externalId, 'stores[0]:', sandbox[0].externalId);

const b = await chromium.launch();
const page = await b.newPage({ ignoreHTTPSErrors: true, locale: 'uk-UA', timezoneId: 'Europe/Kyiv', viewport: { width: 1280, height: 900 } });
page.on('console', (m) => { if (m.type() === 'error') console.log(`  [console:error] ${m.text().slice(0, 300)}`); });
page.on('response', (r) => { const u = r.url(); if (u.includes('/api/store/v1')) console.log(`  [net] ${r.status()} ${u.replace(H.store, '')}`); });

async function settle() { await page.waitForTimeout(2500); }

await page.goto(H.store + '/');
await page.waitForSelector('input[type=password]');
await page.locator('#email').fill(CRED.email);
await page.locator('#password').fill(CRED.password);
await Promise.all([page.waitForResponse((r) => r.url().includes('/auth/login')), page.locator('button[type=submit]').click()]);
await page.waitForFunction(() => !location.pathname.includes('/login'));
await settle();

console.log('\n########## M-01.6 switch + reload ##########');
const values = await page.locator('.appbar__select option').evaluateAll((e) => e.map((x) => x.value));
console.log('option values[0..2]:', JSON.stringify(values.slice(0, 3)));
await page.locator('.appbar__select').selectOption(values[1]);
await settle();
const chosen = await page.locator('.appbar__select').inputValue();
console.log('chosen:', chosen, 'ls:', await page.evaluate(() => localStorage.getItem('yms.store.selectedStoreId')));
await page.reload();
await settle();
console.log('after reload select:', await page.locator('.appbar__select').inputValue());
console.log('after reload ls:', await page.evaluate(() => localStorage.getItem('yms.store.selectedStoreId')));

console.log('\n########## select primary ##########');
await page.locator('.appbar__select').selectOption(primary.storeId);
await settle();
console.log('cards:', await page.locator('.board__col article.bcard').count());

console.log('\n########## M-02.3 empty day (+13) ##########');
const picker = page.locator('input[type=date]').first();
await picker.fill(key(13));
await picker.dispatchEvent('change');
await settle();
const t = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
console.log('has emptyDay text:', t.includes('На цю дату немає бронювань'));
console.log('board region text:', t.slice(t.indexOf('Очистити')).slice(0, 300));

console.log('\n########## M-06.2 yesterday ##########');
await page.getByRole('button', { name: 'Вчора', exact: true }).click();
await settle();
const t2 = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
console.log('has "Минула дата":', t2.includes('Минула дата'));
console.log('banner:', await page.locator('.banner').allInnerTexts());
console.log('date label:', await page.locator('.page__titles .muted').innerText());

console.log('\n########## M-06.1 today vs tomorrow ##########');
await page.getByRole('button', { name: 'Сьогодні', exact: true }).first().click();
await settle();
console.log('today date label:', await page.locator('.page__titles .muted').innerText(), 'cards:', await page.locator('article.bcard').count());
await page.getByRole('button', { name: 'Завтра', exact: true }).click();
await settle();
console.log('tomorrow date label:', await page.locator('.page__titles .muted').innerText(), 'cards:', await page.locator('article.bcard').count());
await page.getByRole('button', { name: 'Сьогодні', exact: true }).first().click();
await settle();
console.log('back today label:', await page.locator('.page__titles .muted').innerText(), 'cards:', await page.locator('article.bcard').count());

console.log('\n########## M-06.3 week ##########');
await page.getByRole('link', { name: 'Розклад тижня' }).click();
await settle();
const t3 = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
console.log('has "Розклад тижня":', t3.includes('Розклад тижня'), '| has "Тільки перегляд":', t3.includes('Тільки перегляд'));
console.log('week__day count:', await page.locator('.week__day').count(), '| legend items:', await page.locator('.legend__item').count());
console.log('page text:', t3.slice(t3.indexOf('Розклад тижня')).slice(0, 400));
const nextBtn = await page.getByRole('button', { name: 'Наступний тиждень' }).count();
console.log('next-week button count:', nextBtn);
if (nextBtn) { await page.getByRole('button', { name: 'Наступний тиждень' }).click(); await settle(); console.log('after next: week__day =', await page.locator('.week__day').count()); }

console.log('\n########## M-08.3 fake store id ##########');
await page.goto(H.store + '/today');
await settle();
await page.evaluate(() => localStorage.setItem('yms.store.selectedStoreId', '00000000-0000-0000-0000-000000000000'));
await page.reload();
await settle();
const t4 = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
console.log('page contains fake id:', t4.includes('00000000-0000-0000-0000-000000000000'));
console.log('ls after reload:', await page.evaluate(() => localStorage.getItem('yms.store.selectedStoreId')));
console.log('select value:', await page.locator('.appbar__select').inputValue());

await b.close();
await ctx.dispose();
