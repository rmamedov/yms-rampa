import { chromium, request } from '@playwright/test';
const ctx = await request.newContext({ignoreHTTPSErrors:true});
const tok = (await (await ctx.post('https://admin.104.248.132.130.sslip.io/api/admin/v1/auth/login',{data:{email:'admin@rampa.ua',password:'${YMS_ADMIN_PASSWORD}'}})).json()).accessToken;
const stores = await (await ctx.get('https://admin.104.248.132.130.sslip.io/api/admin/v1/stores?perPage=20',{headers:{Authorization:'Bearer '+tok}})).json();

const b = await chromium.launch();
const p = await b.newPage({ignoreHTTPSErrors:true, locale:'uk-UA'});
await p.goto('https://admin.104.248.132.130.sslip.io/');
await p.waitForSelector('input[type=password]');
await p.fill('#email','admin@rampa.ua'); await p.fill('#password','${YMS_ADMIN_PASSWORD}');
await Promise.all([p.waitForResponse(r=>r.url().includes('/auth/login')), p.click('button[type=submit]')]);
await p.waitForTimeout(1500);

console.log('=== P-06: повнота мультивибору ===');
await p.goto('https://admin.104.248.132.130.sslip.io/suppliers/41ebd3dd-4435-4b80-a9f8-b7ae6d154b95');
await p.waitForLoadState('networkidle');
await p.click('button:has-text("Магазини")'); await p.waitForTimeout(3500);
await p.click('button:has-text("+8")').catch(()=>{});
await p.waitForTimeout(1200);
const opts = await p.$$eval('[role=option], li, label', els=>els.map(e=>e.innerText.trim()).filter(t=>/^\d{3,}/.test(t)));
console.log(`  варіантів у списку: ${opts.length} (усього філій ${stores.total}, придатних ~447)`);

console.log('\n=== P-05: межі валідації ===');
await p.goto('https://admin.104.248.132.130.sslip.io/stores');
await p.waitForLoadState('networkidle');
const r2 = await ctx.get('https://admin.104.248.132.130.sslip.io/api/admin/v1/stores?q=2226&perPage=20',{headers:{Authorization:'Bearer '+tok}});
const sid = (await r2.json()).items[0].branchId;
await p.goto('https://admin.104.248.132.130.sslip.io/stores/'+sid);
await p.waitForLoadState('networkidle');
await p.click('button:has-text("Обмеження")').catch(()=>{});
await p.waitForTimeout(1500);
const maxes = await p.$$eval('input[type=number]', els=>els.map(e=>({label:(e.labels&&e.labels[0]?e.labels[0].innerText.trim():''), max:e.max})));
console.log(' ', JSON.stringify(maxes));
await b.close();
