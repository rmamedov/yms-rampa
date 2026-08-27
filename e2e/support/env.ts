/** Спільні адреси, доступи і помічники для UI-тестів. */
import { APIRequestContext, Page, request } from '@playwright/test';
import { appendFileSync } from 'node:fs';
import { join } from 'node:path';

const IP = '104.248.132.130';

export const HOSTS = {
  supplier: `https://yms.${IP}.sslip.io`,
  driver: `https://driver.${IP}.sslip.io`,
  store: `https://store.${IP}.sslip.io`,
  admin: `https://admin.${IP}.sslip.io`,
};

export const CREDS = {
  admin: { email: 'admin@rampa.ua', password: '${YMS_ADMIN_PASSWORD}' },
  supplier: { login: 'supplier@rampa.ua', password: '${YMS_SUPPLIER_PASSWORD}' },
};

/** Маркер тестових даних — за ним працює прибирання. */
export const MARK = 'UITEST';

/** Реєстр створеного: щоб потім було що прибирати. */
export function registerArtifact(kind: string, id: string, note = ''): void {
  const line = JSON.stringify({ kind, id, note, at: new Date().toISOString() });
  appendFileSync(join(__dirname, '..', 'artifacts.jsonl'), line + '\n');
}

/** Токен адміністратора для звірки UI з фактичними даними API. */
export async function adminToken(ctx: APIRequestContext): Promise<string> {
  const res = await ctx.post(`${HOSTS.admin}/api/admin/v1/auth/login`, { data: CREDS.admin });
  if (!res.ok()) throw new Error(`Вхід адміністратора не вдався: ${res.status()} ${await res.text()}`);
  return (await res.json()).accessToken;
}

export async function supplierToken(ctx: APIRequestContext): Promise<string> {
  const res = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/auth/login`, { data: CREDS.supplier });
  if (!res.ok()) throw new Error(`Вхід постачальника не вдався: ${res.status()}`);
  return (await res.json()).accessToken;
}

/** Окремий API-контекст (не залежить від сторінки). */
export async function api(): Promise<APIRequestContext> {
  return request.newContext({ ignoreHTTPSErrors: true });
}

/**
 * Вхід через UI. Angular із сигналами не бачить value, виставлене напряму,
 * тому заповнюємо через нативний сеттер і подію input — так само, як це
 * робить справжнє введення з клавіатури.
 */
export async function loginUi(page: Page, host: string, fields: Record<string, string>): Promise<void> {
  await page.goto(host + '/');
  await page.waitForSelector('input[type=password]', { timeout: 30_000 });

  for (const [selector, value] of Object.entries(fields)) {
    await page.locator(selector).first().fill(value);
  }

  await Promise.all([
    // Чекаємо саме на відповідь входу: networkidle тут повертається одразу,
    // і наступна навігація встигає перервати логін.
    page.waitForResponse((r) => r.url().includes('/auth/login') && r.request().method() === 'POST', { timeout: 30_000 }),
    page.locator('button[type=submit]').first().click(),
  ]);

  await page.waitForFunction(() => !location.pathname.includes('/login'), undefined, { timeout: 30_000 });
  await page.waitForLoadState('networkidle');
}

/** Текст усієї сторінки — для перевірок «чи є на екрані». */
export async function pageText(page: Page): Promise<string> {
  return (await page.locator('body').innerText()).replace(/\s+/g, ' ');
}

/** Чи є горизонтальний скрол сторінки (перевірка адаптивності). */
export async function hasHorizontalScroll(page: Page): Promise<boolean> {
  return page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
}

/** Незакриті ключі перекладу і латиниця в інтерфейсі. */
export function untranslatedFragments(text: string): string[] {
  const found: string[] = [];
  const keyLike = text.match(/\b[a-z][a-z0-9]*(\.[a-z][a-zA-Z0-9]*){2,}\b/g);
  if (keyLike) found.push(...new Set(keyLike));
  return found;
}
