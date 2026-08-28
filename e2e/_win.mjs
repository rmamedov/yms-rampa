import { request } from '@playwright/test';
const IP='104.248.132.130';
const S=`https://yms.${IP}.sslip.io`;
const ctx = await request.newContext({ ignoreHTTPSErrors: true });
const token = (await (await ctx.post(`${S}/api/supplier/v1/auth/login`, { data:{login:'supplier@rampa.ua',password:'${YMS_SUPPLIER_PASSWORD}'}})).json()).accessToken;
const H = { Authorization: `Bearer ${token}` };
const now = new Date();
const kyiv = new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Kyiv',year:'numeric',month:'2-digit',day:'2-digit'}).format(now);
const hhmm = (iso) => new Intl.DateTimeFormat('uk-UA',{timeZone:'Europe/Kyiv',hour:'2-digit',minute:'2-digit',hour12:false}).format(new Date(iso));
console.log('зараз UTC', now.toISOString(), '| Київ', hhmm(now), '| дата', kyiv);
for (const city of ['Київ','Харків']) {
  const stores = (await (await ctx.get(`${S}/api/supplier/v1/stores?city=${encodeURIComponent(city)}`, {headers:H})).json()).items;
  console.log(`\n=== ${city}: ${stores.length} філій ===`);
  for (const st of stores.slice(0, 8)) {
    const g = await (await ctx.get(`${S}/api/supplier/v1/stores/${st.storeId}/slots?date=${kyiv}`, {headers:H})).json();
    const av = (g.slots ?? []).filter(s=>s.state==='available').sort((a,b)=>a.slotStart.localeCompare(b.slotStart));
    const sel = (g.slots ?? []).filter(s=>s.selectable).sort((a,b)=>a.slotStart.localeCompare(b.slotStart));
    console.log(`${st.externalId} ${st.name?.slice(0,28)} | lead ${g.leadTimeMinutes} | усіх ${g.slots?.length} | available ${av.length} [${av[0]?hhmm(av[0].slotStart):'—'}..${av.at(-1)?hhmm(av.at(-1).slotStart):'—'}] | selectable ${sel.length} [${sel[0]?hhmm(sel[0].slotStart):'—'}..${sel.at(-1)?hhmm(sel.at(-1).slotStart):'—'}]`);
  }
}
