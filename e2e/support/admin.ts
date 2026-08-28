/**
 * Помічники для UI-тестів адмін-панелі.
 *
 * Правило набору: очікуване значення береться з API, а не з голови автора.
 * Тому тут майже все — тонкі обгортки над реальними запитами до бекенду
 * і над реальними елементами Angular-інтерфейсу.
 */
import { APIRequestContext, Locator, Page, expect } from '@playwright/test';
import { CREDS, HOSTS, adminToken, api, registerArtifact } from './env';

export const ADMIN = HOSTS.admin;
export const ADMIN_API = `${ADMIN}/api/admin/v1`;

/** Пісочниця: конфігурації міняємо ТІЛЬКИ на цих філіях Харкова. */
export const SANDBOX_EXTERNAL_IDS = ['2226', '2227', '2229', '2230'] as const;

/**
 * Дата в часовому поясі магазину (Europe/Kyiv) зі зсувом у днях.
 * Саме нею оперує застосунок, тож UTC тут не годиться: увечері за Києвом
 * UTC-дата вже на добу позаду, і «завтра» перетворюється на «сьогодні».
 */
export function kyivDay(offset = 0): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(
    new Date(Date.now() + offset * 86_400_000),
  );
}

/** Унікальна мітка прогону — щоб тестові дані різних прогонів не змішувались. */
export const RUN_MARK = `${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

/** ЄДРПОУ для тестових постачальників — діапазон 99000000–99009999. */
let edrpouCursor = 99000000 + Math.floor(Math.random() * 9000);
export function nextTestEdrpou(): string {
  edrpouCursor += 1;
  return String(edrpouCursor);
}

export function testSupplierName(suffix: string): string {
  return `UITEST-Постачальник-${RUN_MARK}-${suffix}`;
}

// ---------------------------------------------------------------- API

let cachedToken: string | null = null;
let cachedCtx: APIRequestContext | null = null;

/** Контекст + токен адміністратора, спільні на весь файл тестів. */
export async function adminCtx(): Promise<{ ctx: APIRequestContext; token: string }> {
  if (!cachedCtx) {
    cachedCtx = await api();
  }
  if (!cachedToken) {
    cachedToken = await adminToken(cachedCtx);
  }
  return { ctx: cachedCtx, token: cachedToken };
}

/** GET до /api/admin/v1 з розбором JSON. Кидає, якщо відповідь не 2xx. */
export async function apiGet<T = any>(path: string): Promise<T> {
  const { ctx, token } = await adminCtx();
  const res = await ctx.get(`${ADMIN_API}${path}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  if (!res.ok()) {
    throw new Error(`GET ${path} → ${res.status()} ${await res.text()}`);
  }
  return (await res.json()) as T;
}

/** GET без падіння: повертає статус і тіло — для перевірок відмов. */
export async function apiRaw(
  method: 'get' | 'post' | 'patch' | 'delete',
  path: string,
  data?: unknown,
): Promise<{ status: number; body: any }> {
  const { ctx, token } = await adminCtx();
  const res = await ctx.fetch(`${ADMIN_API}${path}`, {
    method: method.toUpperCase(),
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    data: data === undefined ? undefined : JSON.stringify(data),
  });
  let body: any = null;
  try {
    body = await res.json();
  } catch {
    body = await res.text();
  }
  return { status: res.status(), body };
}

export interface StoreRow {
  branchId: string;
  externalId: string;
  displayName: string;
  city: string;
  address: string;
  ymsStatus: string;
  configured: boolean;
  rampCount: number;
}

export async function apiStores(query: string): Promise<{ total: number; items: StoreRow[] }> {
  const sep = query.startsWith('?') ? '' : '?';
  return apiGet(`/stores${sep}${query}`);
}

/** Загальна кількість магазинів без фільтрів — еталон для X-01. */
export async function apiStoreTotal(): Promise<number> {
  return (await apiStores('perPage=20')).total;
}

export async function apiCities(): Promise<{ city: string; storeCount: number }[]> {
  return (await apiGet<{ items: { city: string; storeCount: number }[] }>('/stores/cities')).items;
}

export interface SupplierDto {
  id: string;
  name: string;
  edrpou: string | null;
  status: string;
  storeAccess: { allStores: boolean; storeIds: string[] };
  contacts: { name: string; phone: string | null; email: string | null }[];
}

export async function apiSuppliers(query = 'limit=100&offset=0'): Promise<{ total: number; items: SupplierDto[] }> {
  return apiGet(`/suppliers?${query}`);
}

/** Знаходить філію-пісочницю за externalId. */
export async function sandboxStore(externalId: string): Promise<StoreRow> {
  const page = await apiStores(`q=${externalId}&perPage=20`);
  const row = page.items.find((i) => i.externalId === externalId);
  if (!row) {
    throw new Error(`Філію-пісочницю ${externalId} не знайдено у списку магазинів`);
  }
  return row;
}

export async function apiConfiguration(branchId: string): Promise<any> {
  return apiGet(`/stores/${branchId}/configurations/current`);
}

// ---------------------------------------------------------------- UI

/** Вхід адміністратором через справжню форму входу. */
export async function loginAdmin(page: Page): Promise<void> {
  await page.goto(`${ADMIN}/login`);
  await page.waitForSelector('#password', { timeout: 30_000 });
  await page.locator('#email').fill(CREDS.admin.email);
  await page.locator('#password').fill(CREDS.admin.password);
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('/auth/login') && r.request().method() === 'POST',
      { timeout: 30_000 },
    ),
    page.locator('button[type=submit]').click(),
  ]);
  await page.waitForFunction(() => !location.pathname.includes('/login'), undefined, {
    timeout: 30_000,
  });
  await page.waitForLoadState('networkidle');
}

/** Перехід усередині SPA з очікуванням завершення запитів. */
export async function goto(page: Page, path: string): Promise<void> {
  await page.goto(ADMIN + path);
  await page.waitForLoadState('networkidle');
}

export async function bodyText(page: Page): Promise<string> {
  return (await page.locator('body').innerText()).replace(/ /g, ' ');
}

/** Кількість рядків у головній таблиці (порожній стан не рахується). */
export async function dataRowCount(page: Page): Promise<number> {
  const rows = page.locator('table.data tbody tr');
  const n = await rows.count();
  if (n === 1 && (await rows.first().locator('app-empty-state').count()) > 0) {
    return 0;
  }
  return n;
}

/** Значення лічильника «Усього: N» у блоці пагінації. */
export async function paginationTotal(page: Page): Promise<number> {
  const text = await page.locator('.pagination').first().innerText();
  const m = text.match(/Усього:\s*(\d+)/);
  if (!m) {
    throw new Error(`У блоці пагінації немає лічильника «Усього»: ${text}`);
  }
  return Number(m[1]);
}

/** Поточна сторінка і їх загальна кількість із «Сторінка X з Y». */
export async function paginationPages(page: Page): Promise<{ page: number; pages: number }> {
  const text = await page.locator('.pagination').first().innerText();
  const m = text.match(/Сторінка\s*(\d+)\s*з\s*(\d+)/);
  if (!m) {
    throw new Error(`У блоці пагінації немає «Сторінка X з Y»: ${text}`);
  }
  return { page: Number(m[1]), pages: Number(m[2]) };
}

/** Мультивибір (місто / статус / магазини) за підписом. */
export function multiSelect(page: Page, label: string): Locator {
  return page.locator('.multi-select').filter({ has: page.locator(`.field-label:text-is("${label}")`) });
}

export async function openMultiSelect(page: Page, label: string): Promise<Locator> {
  const root = multiSelect(page, label);
  if ((await root.locator('.multi-select-panel').count()) === 0) {
    await root.locator('.multi-select-trigger').click();
  }
  await root.locator('.multi-select-panel').waitFor({ state: 'visible' });
  return root;
}

/** Підписи всіх варіантів, які мультивибір реально показує. */
export async function multiSelectOptions(page: Page, label: string): Promise<string[]> {
  const root = await openMultiSelect(page, label);
  return root.locator('.multi-select-list label').allInnerTexts();
}

export async function multiSelectSearch(page: Page, label: string, term: string): Promise<string[]> {
  const root = await openMultiSelect(page, label);
  await root.locator('input[type=search]').fill(term);
  await page.waitForTimeout(300);
  return root.locator('.multi-select-list label').allInnerTexts();
}

export async function multiSelectPick(page: Page, label: string, optionText: string): Promise<void> {
  const root = await openMultiSelect(page, label);
  await root
    .locator('.multi-select-list label')
    .filter({ hasText: optionText })
    .first()
    .locator('input[type=checkbox]')
    .check();
  await root.locator('.multi-select-footer button', { hasText: 'Закрити' }).click();
}

/** Текст усіх видимих toast-повідомлень. */
export async function toastTexts(page: Page): Promise<string[]> {
  return page.locator('.toast').allInnerTexts();
}

export async function waitForToast(page: Page, timeout = 12_000): Promise<string> {
  const toast = page.locator('.toast').first();
  await toast.waitFor({ state: 'visible', timeout });
  return (await toast.innerText()).replace(/✕/g, '').trim();
}

export async function dismissToasts(page: Page): Promise<void> {
  const closers = page.locator('.toast .toast-close');
  for (let i = await closers.count(); i > 0; i -= 1) {
    await closers.first().click().catch(() => undefined);
  }
}

/** Тексти всіх видимих помилок полів на екрані. */
export async function fieldErrors(page: Page): Promise<string[]> {
  return page.locator('.field-error, .notice-danger').allInnerTexts();
}

/** Відкрити вкладку картки магазину/постачальника за підписом. */
/**
 * Перехід до розділу налаштувань магазину.
 *
 * Вкладок більше немає: усі секції лежать на одній сторінці й зберігаються
 * однією кнопкою. Тому «відкрити вкладку» тепер означає прокрутити до секції.
 * Назва помічника збережена навмисно — перевірки, написані для вкладок,
 * лишаються чинними, бо перевіряють ті самі поля.
 */
export async function openTab(page: Page, title: string): Promise<void> {
  const anchor = page.locator('.section-nav a', { hasText: title }).first();
  if (await anchor.count()) {
    await anchor.click();
    await page.waitForTimeout(250);
    return;
  }
  // Інші сторінки (наприклад, список магазинів) досі мають звичайні вкладки.
  await page.locator('.tabs button', { hasText: title }).first().click();
  await page.waitForTimeout(250);
}

/** Записує створену сутність у реєстр прибирання. */
export function track(kind: string, id: string, note = ''): void {
  registerArtifact(kind, id, `${note} [run=${RUN_MARK}]`.trim());
}

/** Приблизна перевірка «немає латиниці/ключів перекладу» в українському UI. */
export function untranslated(text: string): string[] {
  const keys = text.match(/\b[a-z][a-z0-9]*(?:\.[a-z][a-zA-Z0-9]*){1,}\b/g) ?? [];
  // Технічні рядки, які легально виглядають як ключі: e-mail, домени, csv-файли.
  return [...new Set(keys)].filter(
    (k) => !k.includes('@') && !/\.(ua|com|test|csv|json|io)$/.test(k),
  );
}

export { expect };
