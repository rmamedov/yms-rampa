import { chromium } from '@playwright/test';
const IP='104.248.132.130';
const b = await chromium.launch();
const p = await b.newPage({ ignoreHTTPSErrors:true, locale:'uk-UA', viewport:{width:1440,height:900} });
await p.goto(`https://store.${IP}.sslip.io/login`);
await p.fill('#email','admin@rampa.ua'); await p.fill('#password', process.env.YMS_ADMIN_PASSWORD);
await Promise.all([p.waitForResponse(r=>r.url().includes('/auth/login')), p.click('button[type=submit]')]);
await p.waitForTimeout(3000);
console.log(await p.evaluate(() => {
  const cs = (s, prop) => { const e=document.querySelector(s); return e ? getComputedStyle(e)[prop] : 'НЕМАЄ'; };
  const rect = (s) => { const e=document.querySelector(s); if(!e) return null; const r=e.getBoundingClientRect(); return `${Math.round(r.x)},${Math.round(r.y)} ${Math.round(r.width)}x${Math.round(r.height)}`; };
  return JSON.stringify({
    кнопкаФільтрів: cs('.filters__toggle','display'),
    полеПошукуPaddingLeft: cs('.searchfield__input','paddingLeft'),
    іконкаПошуку: rect('.searchfield__icon'),
    полеПошуку: rect('.searchfield__input'),
    тулбар: rect('.page__toolbar'),
    датаПоле: rect('.datenav__picker'),
    датаГрупа: rect('.datenav'),
    сегменти: rect('.segmented'),
  }, null, 1);
}));
await b.close();
