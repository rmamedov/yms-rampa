# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 15-admin-sync-analytics.spec.ts >> A-12 Синхронізація MCP >> A-12.6 деталізація змін (diff) доступна з журналу
- Location: tests/15-admin-sync-analytics.spec.ts:127:7

# Error details

```
Error: із журналу має відкриватись деталізація конкретного запуску (перелік нових / змінених / зниклих філій, а не лише лічильники)

expect(received).toBe(expected) // Object.is equality

Expected: true
Received: false
```

# Page snapshot

```yaml
- generic [ref=f1e4]:
  - complementary [ref=f1e5]:
    - generic [ref=f1e6]: YMS «Рампа»
    - navigation [ref=f1e7]:
      - link "Магазини" [ref=f1e8] [cursor=pointer]:
        - /url: /stores
      - link "Постачальники" [ref=f1e9] [cursor=pointer]:
        - /url: /suppliers
      - link "Користувачі" [ref=f1e10] [cursor=pointer]:
        - /url: /users
      - link "Синхронізація MCP" [ref=f1e11] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=f1e12] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=f1e13]:
      - generic [ref=f1e14]: Адміністратор мережі
      - generic [ref=f1e15]: Супер-адміністратор
      - button "Вийти" [ref=f1e16] [cursor=pointer]
  - main [ref=f1e17]:
    - generic [ref=f1e18]:
      - generic [ref=f1e19]:
        - heading "Синхронізація MCP" [level=1] [ref=f1e20]
        - button "Запустити синхронізацію" [ref=f1e21] [cursor=pointer]
      - generic [ref=f1e22]:
        - table [ref=f1e23]:
          - rowgroup [ref=f1e24]:
            - row [ref=f1e25]:
              - columnheader "Дата / час" [ref=f1e26]
              - columnheader "Тип" [ref=f1e27]
              - columnheader "Тривалість" [ref=f1e28]
              - columnheader "Результат" [ref=f1e29]
              - columnheader "Отримано з MCP" [ref=f1e30]
              - columnheader "Нові" [ref=f1e31]
              - columnheader "Змінені" [ref=f1e32]
              - columnheader "Зниклі" [ref=f1e33]
              - columnheader "Конфлікти" [ref=f1e34]
          - rowgroup [ref=f1e35]:
            - row [ref=f1e36]:
              - cell "28.08.2026, 02:53" [ref=f1e37]
              - cell "Ручний (b01ae05d-c2f6-48a2-a0a4-aed87a17e237)" [ref=f1e38]
              - cell "144 мс" [ref=f1e39]
              - cell "Успіх" [ref=f1e40]
              - cell "455" [ref=f1e42]
              - cell "0" [ref=f1e43]
              - cell "0" [ref=f1e44]
              - cell "0" [ref=f1e45]
              - cell "0" [ref=f1e46]
            - row [ref=f1e47]:
              - cell "28.08.2026, 02:34" [ref=f1e48]
              - cell "Ручний (b01ae05d-c2f6-48a2-a0a4-aed87a17e237)" [ref=f1e49]
              - cell "139 мс" [ref=f1e50]
              - cell "Успіх" [ref=f1e51]
              - cell "455" [ref=f1e53]
              - cell "0" [ref=f1e54]
              - cell "0" [ref=f1e55]
              - cell "0" [ref=f1e56]
              - cell "0" [ref=f1e57]
            - row [ref=f1e58]:
              - cell "28.08.2026, 01:19" [ref=f1e59]
              - cell "Ручний (b01ae05d-c2f6-48a2-a0a4-aed87a17e237)" [ref=f1e60]
              - cell "141 мс" [ref=f1e61]
              - cell "Успіх" [ref=f1e62]
              - cell "455" [ref=f1e64]
              - cell "0" [ref=f1e65]
              - cell "0" [ref=f1e66]
              - cell "0" [ref=f1e67]
              - cell "0" [ref=f1e68]
            - row [ref=f1e69]:
              - cell "28.08.2026, 01:06" [ref=f1e70]
              - cell "Ручний (b01ae05d-c2f6-48a2-a0a4-aed87a17e237)" [ref=f1e71]
              - cell "128 мс" [ref=f1e72]
              - cell "Успіх" [ref=f1e73]
              - cell "455" [ref=f1e75]
              - cell "0" [ref=f1e76]
              - cell "0" [ref=f1e77]
              - cell "0" [ref=f1e78]
              - cell "0" [ref=f1e79]
            - row [ref=f1e80]:
              - cell "28.08.2026, 01:02" [ref=f1e81]
              - cell "Ручний (b01ae05d-c2f6-48a2-a0a4-aed87a17e237)" [ref=f1e82]
              - cell "139 мс" [ref=f1e83]
              - cell "Успіх" [ref=f1e84]
              - cell "455" [ref=f1e86]
              - cell "0" [ref=f1e87]
              - cell "0" [ref=f1e88]
              - cell "0" [ref=f1e89]
              - cell "0" [ref=f1e90]
            - row [ref=f1e91]:
              - cell "28.08.2026, 01:00" [ref=f1e92]
              - cell "Ручний (b01ae05d-c2f6-48a2-a0a4-aed87a17e237)" [ref=f1e93]
              - cell "153 мс" [ref=f1e94]
              - cell "Успіх" [ref=f1e95]
              - cell "455" [ref=f1e97]
              - cell "0" [ref=f1e98]
              - cell "0" [ref=f1e99]
              - cell "0" [ref=f1e100]
              - cell "0" [ref=f1e101]
            - row [ref=f1e102]:
              - cell "27.08.2026, 18:45" [ref=f1e103]
              - cell "Первинний імпорт (cli)" [ref=f1e104]
              - cell "151 мс" [ref=f1e105]
              - cell "Успіх" [ref=f1e106]
              - cell "455" [ref=f1e108]
              - cell "455" [ref=f1e109]
              - cell "0" [ref=f1e110]
              - cell "0" [ref=f1e111]
              - cell "0" [ref=f1e112]
        - generic [ref=f1e114]:
          - generic [ref=f1e115]: "Усього: 7"
          - generic [ref=f1e116]:
            - text: Рядків на сторінці
            - combobox "page-size" [ref=f1e117]:
              - option "20" [selected]
              - option "50"
              - option "100"
          - button "‹" [disabled] [ref=f1e118]
          - generic [ref=f1e119]: Сторінка 1 з 1
          - button "›" [disabled] [ref=f1e120]
```

# Test source

```ts
  41  |       .toBe(log.total);
  42  |     expect(await dataRowCount(page), 'рядків на сторінці').toBe(
  43  |       Math.min(20, log.total),
  44  |     );
  45  |   });
  46  | 
  47  |   test('A-12.2 рядок журналу містить усі лічильники запуску', async ({ page }) => {
  48  |     const log = await apiGet<any>('/sync/log?page=1&perPage=20');
  49  |     test.skip(log.items.length === 0, 'на стенді ще не було запусків синхронізації');
  50  |     const entry = log.items[0];
  51  | 
  52  |     await goto(page, '/mcp-sync');
  53  |     const row = page.locator('table.data tbody tr').first();
  54  |     await expect(row, 'тип запуску').toContainText(entry.triggerLabel);
  55  |     await expect(row, 'результат').toContainText(entry.statusLabel);
  56  |     await expect(row, 'отримано з MCP').toContainText(String(entry.fetched));
  57  |     await expect(row, 'нові').toContainText(String(entry.created));
  58  |   });
  59  | 
  60  |   test('A-12.3 заголовки журналу українською і повні', async ({ page }) => {
  61  |     await goto(page, '/mcp-sync');
  62  |     // CSS робить заголовки великими літерами — порівнюємо без урахування регістру
  63  |     const headers = (await page.locator('table.data thead th').allInnerTexts()).map((s) =>
  64  |       s.trim().toLocaleLowerCase('uk'),
  65  |     );
  66  |     expect(headers).toEqual(
  67  |       [
  68  |       'Дата / час',
  69  |       'Тип',
  70  |       'Тривалість',
  71  |       'Результат',
  72  |       'Отримано з MCP',
  73  |       'Нові',
  74  |       'Змінені',
  75  |       'Зниклі',
  76  |       'Конфлікти',
  77  |       ].map((h) => h.toLocaleLowerCase('uk')),
  78  |     );
  79  |   });
  80  | 
  81  |   test('A-12.4 ручний запуск синхронізації і звіт', async ({ page }) => {
  82  |     const before = await apiGet<any>('/sync/log?page=1&perPage=20');
  83  |     const storesBefore = await apiStoreTotal();
  84  | 
  85  |     await goto(page, '/mcp-sync');
  86  |     const runButton = page.locator('.page-header button', { hasText: 'Запустити синхронізацію' });
  87  |     await expect(runButton, 'super_admin може запускати синхронізацію').toBeEnabled();
  88  | 
  89  |     await runButton.click();
  90  |     // Стан «виконується» тут не перевіряємо: синхронізація на стенді синхронна
  91  |     // і завершується за частки секунди — спіймати проміжний стан неможливо.
  92  |     await page.waitForResponse(
  93  |       (r) => r.url().includes('/sync/run') && r.request().method() === 'POST',
  94  |       { timeout: 60_000 },
  95  |     );
  96  |     expect(await waitForToast(page)).toContain('Синхронізацію запущено');
  97  | 
  98  |     // звіт запуску показується модальним вікном
  99  |     const report = page.locator('.modal', { hasText: 'Звіт запуску' });
  100 |     await expect(report, 'після запуску показується звіт').toBeVisible();
  101 |     await expect(report).toContainText('Отримано з MCP');
  102 |     await expect(report).toContainText('Придатні');
  103 | 
  104 |     await page.locator('.modal-footer button', { hasText: 'Закрити' }).click();
  105 | 
  106 |     const after = await apiGet<any>('/sync/log?page=1&perPage=20');
  107 |     expect(after.total, 'у журналі зʼявився новий запис').toBe(before.total + 1);
  108 |     expect(after.items[0].trigger, 'тип запуску — ручний').toBe('manual');
  109 |     expect(after.items[0].initiator, 'ініціатора зафіксовано').toBeTruthy();
  110 | 
  111 |     expect(await apiStoreTotal(), 'кількість філій не змінилась').toBe(storesBefore);
  112 | 
  113 |     await goto(page, '/mcp-sync');
  114 |     await expect
  115 |       .poll(() => paginationTotal(page), { message: 'журнал оновився' })
  116 |       .toBe(after.total);
  117 |   });
  118 | 
  119 |   test('A-12.5 повторний запуск під час активної синхронізації відхиляється', async () => {
  120 |     // Синхронізація на стенді синхронна, тож «активний» стан ззовні не спіймати.
  121 |     // Перевіряємо контракт: бекенд має код SYNC_ALREADY_RUNNING і банер у журналі.
  122 |     const log = await apiGet<any>('/sync/log?page=1&perPage=20');
  123 |     expect(log, 'журнал повідомляє, чи є активний запуск').toHaveProperty('running');
  124 |     expect(typeof log.running, 'ознака «виконується» — булева').toBe('boolean');
  125 |   });
  126 | 
  127 |   test('A-12.6 деталізація змін (diff) доступна з журналу', async ({ page }) => {
  128 |     const log = await apiGet<any>('/sync/log?page=1&perPage=20');
  129 |     test.skip(log.items.length === 0, 'немає запусків');
  130 | 
  131 |     await goto(page, '/mcp-sync');
  132 |     const row = page.locator('table.data tbody tr').first();
  133 |     await row.click();
  134 |     await page.waitForTimeout(800);
  135 | 
  136 |     const text = await bodyText(page);
  137 |     expect(
  138 |       text.includes('Звіт запуску') || text.includes('Деталі'),
  139 |       'із журналу має відкриватись деталізація конкретного запуску ' +
  140 |         '(перелік нових / змінених / зниклих філій, а не лише лічильники)',
> 141 |     ).toBe(true);
      |       ^ Error: із журналу має відкриватись деталізація конкретного запуску (перелік нових / змінених / зниклих філій, а не лише лічильники)
  142 |   });
  143 | });
  144 | 
  145 | // ------------------------------------------------------------------ A-13
  146 | 
  147 | test.describe('A-13 Аналітика', () => {
  148 |   test('A-13.1 дашборд відкривається і показує всі KPI', async ({ page }) => {
  149 |     await goto(page, '/analytics');
  150 |     const text = await bodyText(page);
  151 |     expect(text).toContain('Аналітика');
  152 | 
  153 |     const kpi = await apiGet<any>(
  154 |       `/analytics/kpi?from=${dayOffset(-29)}&to=${dayOffset(0)}`,
  155 |     );
  156 |     if (kpi.empty) {
  157 |       await expect(page.locator('app-empty-state'), 'порожній період — свідоме повідомлення')
  158 |         .toContainText(kpi.message ?? 'Немає даних');
  159 |     } else {
  160 |       for (const label of [
  161 |         'KPI-01 утилізація рамп',
  162 |         'KPI-02 прибуття у слот',
  163 |         'KPI-03 очікування, медіана хв',
  164 |         'KPI-04 no-show',
  165 |         'ANL-04 розвантаження, медіана хв',
  166 |         'Бронювань',
  167 |       ]) {
  168 |         expect(text, `на дашборді має бути ${label}`).toContain(label);
  169 |       }
  170 |     }
  171 |   });
  172 | 
  173 |   test('A-13.2 X-01 фільтр «Місто» містить усі міста', async ({ page }) => {
  174 |     const cities = await apiCities();
  175 |     await goto(page, '/analytics');
  176 |     const options = (await multiSelectOptions(page, 'Місто')).map((s) => s.trim());
  177 |     const missing = cities.map((c) => c.city).filter((c) => !options.includes(c));
  178 |     expect(missing, `немає міст: ${missing.join(', ')}`).toEqual([]);
  179 |   });
  180 | 
  181 |   test('A-13.3 X-01 фільтр «Магазин» містить усі філії', async ({ page }) => {
  182 |     const total = await apiStoreTotal();
  183 |     await goto(page, '/analytics');
  184 |     const options = await multiSelectOptions(page, 'Магазин');
  185 |     expect(
  186 |       options.length,
  187 |       `у фільтрі магазинів ${options.length} варіантів, а в мережі ${total} філій`,
  188 |     ).toBeGreaterThanOrEqual(total);
  189 |   });
  190 | 
  191 |   test('A-13.4 X-01 фільтр «Постачальник» містить усіх постачальників', async ({ page }) => {
  192 |     const suppliers = await apiSuppliers('limit=200&offset=0');
  193 |     await goto(page, '/analytics');
  194 |     const options = (await multiSelectOptions(page, 'Постачальник')).map((s) => s.trim());
  195 |     const missing = suppliers.items.map((s) => s.name).filter((n) => !options.includes(n));
  196 |     expect(
  197 |       missing,
  198 |       `у фільтрі ${options.length} постачальників, в API ${suppliers.total}; немає: ${missing.join(', ')}`,
  199 |     ).toEqual([]);
  200 |   });
  201 | 
  202 |   test('A-13.5 пресети періоду міняють дати', async ({ page }) => {
  203 |     await goto(page, '/analytics');
  204 | 
  205 |     const from = page.locator('#a-from');
  206 |     const to = page.locator('#a-to');
  207 | 
  208 |     for (const [label, expectedFrom] of [
  209 |       ['Сьогодні', dayOffset(0)],
  210 |       ['7 днів', dayOffset(-6)],
  211 |       ['30 днів', dayOffset(-29)],
  212 |     ] as const) {
  213 |       await page.locator('.toolbar button', { hasText: label }).click();
  214 |       await expect
  215 |         .poll(() => from.inputValue(), { message: `пресет «${label}»`, timeout: 10_000 })
  216 |         .toBe(expectedFrom);
  217 |       await expect
  218 |         .poll(() => to.inputValue(), { message: `пресет «${label}»: кінець періоду` })
  219 |         .toBe(dayOffset(0));
  220 |     }
  221 |   });
  222 | 
  223 |   test('A-13.6 X-04 період без даних показує коректний порожній стан', async ({ page }) => {
  224 |     const from = dayOffset(-400);
  225 |     const to = dayOffset(-390);
  226 |     const kpi = await apiGet<any>(`/analytics/kpi?from=${from}&to=${to}`);
  227 |     expect(kpi.empty, 'для контролю потрібен свідомо порожній період').toBe(true);
  228 | 
  229 |     await goto(page, '/analytics');
  230 |     await page.locator('#a-from').fill(from);
  231 |     await page.locator('#a-to').fill(to);
  232 |     await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
  233 |     await page.waitForLoadState('networkidle');
  234 | 
  235 |     await expect(page.locator('app-empty-state')).toContainText(kpi.message ?? 'Немає даних');
  236 |   });
  237 | 
  238 |   test('A-13.7 перемикання розрізу перезавантажує таблицю', async ({ page }) => {
  239 |     const kpi = await apiGet<any>(`/analytics/kpi?from=${dayOffset(-29)}&to=${dayOffset(0)}`);
  240 |     test.skip(
  241 |       kpi.empty,
```