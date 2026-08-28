// Разовий діагностичний скрипт. Доступи — зі змінних оточення:
//   export YMS_ADMIN_PASSWORD='...'  (див. .env.example)
import { chromium } from '@playwright/test';

const IP = process.env.YMS_IP ?? '104.248.132.130';
const ADMIN = `https://admin.${IP}.sslip.io`;
const EMAIL = process.env.YMS_ADMIN_EMAIL ?? 'admin@rampa.ua';
const PASSWORD = process.env.YMS_ADMIN_PASSWORD;
if (!PASSWORD) {
  console.error("Не задано YMS_ADMIN_PASSWORD — див. .env.example у корені репозиторію.");
  process.exit(2);
}

const b = await chromium.launch();
const p = await b.newPage({ignoreHTTPSErrors:true, locale:'uk-UA'});
p.on('console', m => { if (m.type()==='error') console.log('  [console error]', m.text().slice(0,160)); });
p.on('pageerror', e => console.log('  [page error]', String(e).slice(0,200)));
await p.goto(`${ADMIN}/`);
await p.waitForSelector('input[type=password]');
await p.fill('#email', EMAIL); await p.fill('#password', PASSWORD);
await Promise.all([p.waitForResponse(r=>r.url().includes('/auth/login')), p.click('button[type=submit]')]);
await p.goto(`${ADMIN}/analytics`);
await p.waitForTimeout(4000);
const info = await p.evaluate(() => ({
  kpiGrid: !!document.querySelector('.kpi-grid'),
  emptyState: !!document.querySelector('app-empty-state'),
  bodyLen: document.body.innerText.length,
  tail: document.body.innerText.slice(-300).replace(/\s+/g,' '),
}));
console.log(' ', JSON.stringify(info, null, 0));
await b.close();
