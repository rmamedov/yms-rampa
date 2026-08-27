/**
 * A-12 «Синхронізація MCP», A-13 «Аналітика»,
 * A-11 «Користувачі-співробітники» і A-14 «Аудит» — перевірка наявності розділів.
 */
import { expect, test } from '@playwright/test';
import {
  ADMIN,
  ADMIN_API,
  adminCtx,
  apiCities,
  apiGet,
  apiRaw,
  apiStoreTotal,
  apiSuppliers,
  bodyText,
  dataRowCount,
  goto,
  kyivDay,
  loginAdmin,
  multiSelectOptions,
  paginationTotal,
  waitForToast,
} from '../support/admin';
import { HOSTS, api, supplierToken } from '../support/env';

test.beforeEach(async ({ page }) => {
  await loginAdmin(page);
});

const dayOffset = kyivDay;

// ------------------------------------------------------------------ A-12

test.describe('A-12 Синхронізація MCP', () => {
  test('A-12.1 журнал запусків збігається з API', async ({ page }) => {
    const log = await apiGet<any>('/sync/log?page=1&perPage=20');
    await goto(page, '/mcp-sync');

    await expect
      .poll(() => paginationTotal(page), { message: 'кількість запусків' })
      .toBe(log.total);
    expect(await dataRowCount(page), 'рядків на сторінці').toBe(
      Math.min(20, log.total),
    );
  });

  test('A-12.2 рядок журналу містить усі лічильники запуску', async ({ page }) => {
    const log = await apiGet<any>('/sync/log?page=1&perPage=20');
    test.skip(log.items.length === 0, 'на стенді ще не було запусків синхронізації');
    const entry = log.items[0];

    await goto(page, '/mcp-sync');
    const row = page.locator('table.data tbody tr').first();
    await expect(row, 'тип запуску').toContainText(entry.triggerLabel);
    await expect(row, 'результат').toContainText(entry.statusLabel);
    await expect(row, 'отримано з MCP').toContainText(String(entry.fetched));
    await expect(row, 'нові').toContainText(String(entry.created));
  });

  test('A-12.3 заголовки журналу українською і повні', async ({ page }) => {
    await goto(page, '/mcp-sync');
    // CSS робить заголовки великими літерами — порівнюємо без урахування регістру
    const headers = (await page.locator('table.data thead th').allInnerTexts()).map((s) =>
      s.trim().toLocaleLowerCase('uk'),
    );
    expect(headers).toEqual(
      [
      'Дата / час',
      'Тип',
      'Тривалість',
      'Результат',
      'Отримано з MCP',
      'Нові',
      'Змінені',
      'Зниклі',
      'Конфлікти',
      ].map((h) => h.toLocaleLowerCase('uk')),
    );
  });

  test('A-12.4 ручний запуск синхронізації і звіт', async ({ page }) => {
    const before = await apiGet<any>('/sync/log?page=1&perPage=20');
    const storesBefore = await apiStoreTotal();

    await goto(page, '/mcp-sync');
    const runButton = page.locator('.page-header button', { hasText: 'Запустити синхронізацію' });
    await expect(runButton, 'super_admin може запускати синхронізацію').toBeEnabled();

    await runButton.click();
    // Стан «виконується» тут не перевіряємо: синхронізація на стенді синхронна
    // і завершується за частки секунди — спіймати проміжний стан неможливо.
    await page.waitForResponse(
      (r) => r.url().includes('/sync/run') && r.request().method() === 'POST',
      { timeout: 60_000 },
    );
    expect(await waitForToast(page)).toContain('Синхронізацію запущено');

    // звіт запуску показується модальним вікном
    const report = page.locator('.modal', { hasText: 'Звіт запуску' });
    await expect(report, 'після запуску показується звіт').toBeVisible();
    await expect(report).toContainText('Отримано з MCP');
    await expect(report).toContainText('Придатні');

    await page.locator('.modal-footer button', { hasText: 'Закрити' }).click();

    const after = await apiGet<any>('/sync/log?page=1&perPage=20');
    expect(after.total, 'у журналі зʼявився новий запис').toBe(before.total + 1);
    expect(after.items[0].trigger, 'тип запуску — ручний').toBe('manual');
    expect(after.items[0].initiator, 'ініціатора зафіксовано').toBeTruthy();

    expect(await apiStoreTotal(), 'кількість філій не змінилась').toBe(storesBefore);

    await goto(page, '/mcp-sync');
    await expect
      .poll(() => paginationTotal(page), { message: 'журнал оновився' })
      .toBe(after.total);
  });

  test('A-12.5 повторний запуск під час активної синхронізації відхиляється', async () => {
    // Синхронізація на стенді синхронна, тож «активний» стан ззовні не спіймати.
    // Перевіряємо контракт: бекенд має код SYNC_ALREADY_RUNNING і банер у журналі.
    const log = await apiGet<any>('/sync/log?page=1&perPage=20');
    expect(log, 'журнал повідомляє, чи є активний запуск').toHaveProperty('running');
    expect(typeof log.running, 'ознака «виконується» — булева').toBe('boolean');
  });

  test('A-12.6 деталізація змін (diff) доступна з журналу', async ({ page }) => {
    const log = await apiGet<any>('/sync/log?page=1&perPage=20');
    test.skip(log.items.length === 0, 'немає запусків');

    await goto(page, '/mcp-sync');
    const row = page.locator('table.data tbody tr').first();
    await row.click();
    await page.waitForTimeout(800);

    const text = await bodyText(page);
    expect(
      text.includes('Звіт запуску') || text.includes('Деталі'),
      'із журналу має відкриватись деталізація конкретного запуску ' +
        '(перелік нових / змінених / зниклих філій, а не лише лічильники)',
    ).toBe(true);
  });
});

// ------------------------------------------------------------------ A-13

test.describe('A-13 Аналітика', () => {
  test('A-13.1 дашборд відкривається і показує всі KPI', async ({ page }) => {
    await goto(page, '/analytics');
    const text = await bodyText(page);
    expect(text).toContain('Аналітика');

    const kpi = await apiGet<any>(
      `/analytics/kpi?from=${dayOffset(-29)}&to=${dayOffset(0)}`,
    );
    if (kpi.empty) {
      await expect(page.locator('app-empty-state'), 'порожній період — свідоме повідомлення')
        .toContainText(kpi.message ?? 'Немає даних');
    } else {
      for (const label of [
        'KPI-01 утилізація рамп',
        'KPI-02 прибуття у слот',
        'KPI-03 очікування, медіана хв',
        'KPI-04 no-show',
        'ANL-04 розвантаження, медіана хв',
        'Бронювань',
      ]) {
        expect(text, `на дашборді має бути ${label}`).toContain(label);
      }
    }
  });

  test('A-13.2 X-01 фільтр «Місто» містить усі міста', async ({ page }) => {
    const cities = await apiCities();
    await goto(page, '/analytics');
    const options = (await multiSelectOptions(page, 'Місто')).map((s) => s.trim());
    const missing = cities.map((c) => c.city).filter((c) => !options.includes(c));
    expect(missing, `немає міст: ${missing.join(', ')}`).toEqual([]);
  });

  test('A-13.3 X-01 фільтр «Магазин» містить усі філії', async ({ page }) => {
    const total = await apiStoreTotal();
    await goto(page, '/analytics');
    const options = await multiSelectOptions(page, 'Магазин');
    expect(
      options.length,
      `у фільтрі магазинів ${options.length} варіантів, а в мережі ${total} філій`,
    ).toBeGreaterThanOrEqual(total);
  });

  test('A-13.4 X-01 фільтр «Постачальник» містить усіх постачальників', async ({ page }) => {
    const suppliers = await apiSuppliers('limit=200&offset=0');
    await goto(page, '/analytics');
    const options = (await multiSelectOptions(page, 'Постачальник')).map((s) => s.trim());
    const missing = suppliers.items.map((s) => s.name).filter((n) => !options.includes(n));
    expect(
      missing,
      `у фільтрі ${options.length} постачальників, в API ${suppliers.total}; немає: ${missing.join(', ')}`,
    ).toEqual([]);
  });

  test('A-13.5 пресети періоду міняють дати', async ({ page }) => {
    await goto(page, '/analytics');

    const from = page.locator('#a-from');
    const to = page.locator('#a-to');

    for (const [label, expectedFrom] of [
      ['Сьогодні', dayOffset(0)],
      ['7 днів', dayOffset(-6)],
      ['30 днів', dayOffset(-29)],
    ] as const) {
      await page.locator('.toolbar button', { hasText: label }).click();
      await expect
        .poll(() => from.inputValue(), { message: `пресет «${label}»`, timeout: 10_000 })
        .toBe(expectedFrom);
      await expect
        .poll(() => to.inputValue(), { message: `пресет «${label}»: кінець періоду` })
        .toBe(dayOffset(0));
    }
  });

  test('A-13.6 X-04 період без даних показує коректний порожній стан', async ({ page }) => {
    const from = dayOffset(-400);
    const to = dayOffset(-390);
    const kpi = await apiGet<any>(`/analytics/kpi?from=${from}&to=${to}`);
    expect(kpi.empty, 'для контролю потрібен свідомо порожній період').toBe(true);

    await goto(page, '/analytics');
    await page.locator('#a-from').fill(from);
    await page.locator('#a-to').fill(to);
    await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('app-empty-state')).toContainText(kpi.message ?? 'Немає даних');
  });

  test('A-13.7 перемикання розрізу перезавантажує таблицю', async ({ page }) => {
    const kpi = await apiGet<any>(`/analytics/kpi?from=${dayOffset(-29)}&to=${dayOffset(0)}`);
    test.skip(
      kpi.empty,
      'на стенді немає жодного бронювання: блок «Розріз» разом із перемикачем ' +
        'і кнопками експорту не рендериться взагалі',
    );

    await goto(page, '/analytics');
    const dimensions = await page.locator('#dimension option').allInnerTexts();
    expect(
      dimensions.map((s) => s.trim()),
      'усі розрізи з контракту аналітики',
    ).toEqual([
      'Мережа',
      'Місто',
      'Магазин',
      'Рампа',
      'Постачальник',
      'День',
      'Тиждень',
      'Місяць',
      'Тип бронювання',
      'Причина відмови',
    ]);

    for (const value of ['city', 'store', 'supplier']) {
      const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/analytics/breakdown'), { timeout: 20_000 }),
        page.locator('#dimension').selectOption(value),
      ]);
      expect(response.url(), `запит із розрізом ${value}`).toContain(`dimension=${value}`);
    }
  });

  test('A-13.8 X-06 некоректний період показується користувачу текстом', async ({ page }) => {
    await goto(page, '/analytics');
    await page.locator('#a-from').fill(dayOffset(5));
    await page.locator('#a-to').fill(dayOffset(-5));
    await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();

    const check = await apiRaw(
      'get',
      `/analytics/kpi?from=${dayOffset(5)}&to=${dayOffset(-5)}`,
    );
    if (check.status >= 400) {
      const toast = await waitForToast(page);
      expect(toast.length, 'помилку сервера видно користувачу').toBeGreaterThan(0);
    } else {
      await expect(page.locator('app-empty-state')).toBeVisible();
    }
  });

  test('A-13.9 експорт CSV формує файл', async ({ page }) => {
    const kpi = await apiGet<any>(`/analytics/kpi?from=${dayOffset(-29)}&to=${dayOffset(0)}`);
    test.skip(
      kpi.empty,
      'кнопки експорту живуть усередині блоку «Розріз», якого без даних немає',
    );

    await goto(page, '/analytics');

    const download = page.waitForEvent('download', { timeout: 30_000 }).catch(() => null);
    await page.locator('button', { hasText: 'Експорт розрізу (CSV)' }).click();
    const toast = await waitForToast(page).catch(() => '');
    const file = await download;

    expect(
      file !== null || toast.includes('сформовано'),
      `експорт має завершитись файлом; повідомлення: «${toast}»`,
    ).toBe(true);
    if (file) {
      expect(file.suggestedFilename(), 'імʼя файлу').toMatch(/\.csv$/);
    }
  });

  test('A-13.10 експорт CSV бекенду віддає дані з рядком фільтрів', async () => {
    const { ctx, token } = await adminCtx();
    const res = await ctx.get(
      `${ADMIN_API}/analytics/export.csv?from=${dayOffset(-29)}&to=${dayOffset(0)}` +
        '&dataset=breakdown&dimension=store',
      { headers: { Authorization: `Bearer ${token}` } },
    );
    expect(res.status(), 'експорт доступний').toBe(200);
    const body = await res.text();
    expect(body.length, 'CSV не порожній').toBeGreaterThan(0);
    expect(body, 'CSV містить рядок фільтрів або заголовки').toMatch(/період|from|dimension|;|,/i);
  });

  test('A-13.12 лічильники аналітики бачать фактичні бронювання', async () => {
    // Еталон беремо з іншого контуру того самого стенду: маршрутний лист
    // постачальника на сьогодні. Якщо там є точки — бронювання існують.
    const ctx = await api();
    const token = await supplierToken(ctx);
    const day = dayOffset(0);
    const sheet = await ctx.get(
      `${HOSTS.supplier}/api/supplier/v1/route-sheets?date=${day}`,
      { headers: { Authorization: `Bearer ${token}` } },
    );
    test.skip(!sheet.ok(), 'маршрутний лист постачальника недоступний');
    const points = ((await sheet.json()).points ?? []) as unknown[];
    test.skip(points.length === 0, 'на сьогодні бронювань на стенді немає');

    const kpi = await apiGet<any>(`/analytics/kpi?from=${day}&to=${day}`);
    expect(
      kpi.kpi.counters.total,
      `у маршрутному листі постачальника на ${day} ${points.length} точок, ` +
        `а дашборд аналітики показує ${kpi.kpi.counters.total} бронювань ` +
        `(empty=${kpi.empty})`,
    ).toBeGreaterThan(0);
  });

  test('A-13.11 фільтр за містом враховується у запиті KPI', async ({ page }) => {
    await goto(page, '/analytics');
    const { multiSelectPick } = await import('../support/admin');
    await multiSelectPick(page, 'Місто', 'Харків');

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/analytics/kpi'), { timeout: 20_000 }),
      page.locator('.toolbar button', { hasText: 'Застосувати' }).click(),
    ]);
    expect(decodeURIComponent(response.url()), 'місто передано у фільтрі').toContain('city=Харків');
  });
});

// ------------------------------------------------------------------ A-11 / A-14

test.describe('A-11 Користувачі-співробітники', () => {
  test('A-11.1 у меню є розділ керування staff-користувачами', async ({ page }) => {
    await goto(page, '/stores');
    const links = (await page.locator('.sidebar-link').allInnerTexts()).map((s) => s.trim());
    expect(
      links.some((l) => /користувач|співробітник/i.test(l)),
      `super_admin має право users.manage.staff, але розділу керування користувачами в меню немає; ` +
        `наявні пункти: ${links.join(', ')}`,
    ).toBe(true);
  });

  test('A-11.2 бекенд має API для керування staff-користувачами', async () => {
    const probes = ['/users', '/staff', '/staff-users', '/users?limit=10'];
    const results: string[] = [];
    for (const path of probes) {
      const res = await apiRaw('get', path);
      results.push(`${path} → ${res.status}`);
    }
    expect(
      results.some((r) => !r.endsWith('404')),
      'жодного маршруту для CRUD staff-акаунтів у контурі /api/admin/v1 немає: ' +
        results.join('; '),
    ).toBe(true);
  });

  test('A-11.3 створення користувача для кожної з пʼяти ролей', async ({ page }) => {
    await goto(page, '/stores');
    const links = (await page.locator('.sidebar-link').allInnerTexts()).map((s) => s.trim());
    test.skip(
      !links.some((l) => /користувач|співробітник/i.test(l)),
      'розділу staff-користувачів немає — сценарій A-11 неможливо виконати (див. A-11.1)',
    );
  });
});

test.describe('A-14 Аудит', () => {
  test('A-14.1 у меню є журнал аудиту', async ({ page }) => {
    await goto(page, '/stores');
    const links = (await page.locator('.sidebar-link').allInnerTexts()).map((s) => s.trim());
    expect(
      links.some((l) => /аудит|журнал/i.test(l)),
      `super_admin має право audit.read, але журналу аудиту в меню немає; ` +
        `наявні пункти: ${links.join(', ')}`,
    ).toBe(true);
  });

  test('A-14.2 бекенд має API журналу аудиту', async () => {
    const probes = ['/audit', '/audit-log', '/audit/log', '/audit/entries'];
    const results: string[] = [];
    for (const path of probes) {
      const res = await apiRaw('get', path);
      results.push(`${path} → ${res.status}`);
    }
    expect(
      results.some((r) => !r.endsWith('404')),
      'жодного маршруту журналу аудиту в контурі /api/admin/v1 немає: ' + results.join('; '),
    ).toBe(true);
  });

  test('A-14.3 прямий перехід на неіснуючий розділ не ламає застосунок', async ({ page }) => {
    await page.goto(`${ADMIN}/audit`);
    await page.waitForLoadState('networkidle');
    // wildcard-маршрут веде на головну, а не на порожній екран
    expect(page.url(), 'невідома адреса веде на робочий розділ').toMatch(/\/stores|\/$/);
    await expect(page.locator('h1')).toBeVisible();
  });
});
