import { chromium, request } from '@playwright/test';

const IP = '104.248.132.130';
const H = {
  supplier: `https://yms.${IP}.sslip.io`,
  store: `https://store.${IP}.sslip.io`,
  admin: `https://admin.${IP}.sslip.io`,
};
const CRED = { email: 'admin@rampa.ua', password: '${YMS_ADMIN_PASSWORD}' };
const ctx = await request.newContext({ ignoreHTTPSErrors: true });

const adminTok = (await (await ctx.post(`${H.admin}/api/admin/v1/auth/login`, { data: CRED })).json()).accessToken;
const supTok = (await (await ctx.post(`${H.supplier}/api/supplier/v1/auth/login`, { data: { login: 'supplier@rampa.ua', password: '${YMS_SUPPLIER_PASSWORD}' } })).json()).accessToken;
const storeLogin = await (await ctx.post(`${H.store}/api/store/v1/auth/login`, { data: CRED })).json();
const storeTok = storeLogin.accessToken;

console.log('=== store login user ===');
console.log(JSON.stringify({ id: storeLogin.user.id, role: storeLogin.user.role, scope: storeLogin.user.scope }, null, 1));

const adminSup = await (await ctx.get(`${H.admin}/api/admin/v1/suppliers?perPage=100`, { headers: { Authorization: 'Bearer ' + adminTok } })).json();
console.log(`\n=== admin suppliers: total=${adminSup.total} items=${adminSup.items.length} ===`);
for (const s of adminSup.items) console.log(` ${s.id} | ${s.status ?? '-'} | ${s.name}`);

// sandbox stores
const cat = await (await ctx.get(`${H.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`, { headers: { Authorization: 'Bearer ' + supTok } })).json();
const sandbox = cat.items.filter((s) => ['2226', '2227', '2229', '2230'].includes(s.externalId));
const withRamps = sandbox.filter((s) => s.ramps.filter((r) => r.active !== false).length > 1).sort((a, b) => b.ramps.length - a.ramps.length);
const primary = withRamps[0];
console.log(`\n=== primary store: ${primary.externalId} ${primary.storeId} ramps=${primary.ramps.length} maxW=${primary.maxVehicleWeightTons} ===`);

const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date());
console.log('today(kyiv)=', today);

const storeSup = await ctx.get(`${H.store}/api/store/v1/stores/${primary.storeId}/suppliers`, { headers: { Authorization: 'Bearer ' + storeTok } });
const storeSupBody = await storeSup.json();
console.log(`\n=== store /suppliers status=${storeSup.status()} count=${Array.isArray(storeSupBody) ? storeSupBody.length : 'n/a'} ===`);
console.log(JSON.stringify(storeSupBody).slice(0, 600));

const storeSlots = await ctx.get(`${H.store}/api/store/v1/stores/${primary.storeId}/slots?date=${today}`, { headers: { Authorization: 'Bearer ' + storeTok } });
const slotsBody = await storeSlots.json();
console.log(`\n=== store /slots?date=${today} status=${storeSlots.status()} type=${Array.isArray(slotsBody) ? 'array len ' + slotsBody.length : typeof slotsBody} ===`);
if (Array.isArray(slotsBody)) {
  const sel = slotsBody.filter((s) => s.selectable);
  console.log(` selectable=${sel.length}; states=${JSON.stringify(slotsBody.reduce((a, s) => ((a[s.state] = (a[s.state] ?? 0) + 1), a), {}))}`);
  console.log(' sample:', JSON.stringify(slotsBody.slice(0, 2)));
  console.log(' sample selectable:', JSON.stringify(sel.slice(0, 2)));
} else {
  console.log(JSON.stringify(slotsBody).slice(0, 800));
}

// supplier-contour slot grid for the same day (ground truth)
const supGrid = await (await ctx.get(`${H.supplier}/api/supplier/v1/stores/${primary.storeId}/slots?date=${today}`, { headers: { Authorization: 'Bearer ' + supTok } })).json();
const supFree = supGrid.slots.filter((s) => s.state === 'available' && s.selectable);
console.log(`\n=== supplier grid: slots=${supGrid.slots.length} free&selectable=${supFree.length} ===`);
console.log(' sample:', JSON.stringify(supGrid.slots.slice(0, 2)));

// week route
const weekRes = await ctx.get(`${H.store}/api/store/v1/stores/${primary.storeId}/slots?from=${today}&days=7`, { headers: { Authorization: 'Bearer ' + storeTok } });
const weekBody = await weekRes.json();
console.log(`\n=== store /slots?from=${today}&days=7 status=${weekRes.status()} ===`);
console.log(JSON.stringify(weekBody).slice(0, 400));

// bookings today / tomorrow
for (const off of [0, 1]) {
  const d = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date(Date.now() + off * 86400000));
  const r = await ctx.get(`${H.store}/api/store/v1/bookings?storeId=${primary.storeId}&date=${d}`, { headers: { Authorization: 'Bearer ' + storeTok } });
  const b = await r.json();
  const list = b.bookings ?? b;
  console.log(`\n=== bookings ${d}: status=${r.status()} n=${list.length} now=${b.now} dates=${JSON.stringify([...new Set(list.map((x) => x.localDate))])}`);
}

// store list route
const stRes = await ctx.get(`${H.store}/api/store/v1/stores`, { headers: { Authorization: 'Bearer ' + storeTok } });
const stBody = await stRes.json();
console.log(`\n=== store /stores status=${stRes.status()} n=${Array.isArray(stBody) ? stBody.length : JSON.stringify(stBody).slice(0,200)} ===`);
if (Array.isArray(stBody)) console.log(' sample:', JSON.stringify(stBody.slice(0, 2)));

// fake store bookings
const fake = '00000000-0000-0000-0000-000000000000';
const fr = await ctx.get(`${H.store}/api/store/v1/bookings?storeId=${fake}&date=${today}`, { headers: { Authorization: 'Bearer ' + storeTok } });
console.log(`\n=== bookings fake store: status=${fr.status()} body=${(await fr.text()).slice(0, 200)}`);

await ctx.dispose();
