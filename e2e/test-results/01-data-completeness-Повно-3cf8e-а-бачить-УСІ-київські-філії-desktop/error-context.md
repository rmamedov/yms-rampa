# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 01-data-completeness.spec.ts >> Повнота даних >> X-01/X-02 адмінка: вибір філій постачальника бачить УСІ київські філії
- Location: tests/01-data-completeness.spec.ts:28:7

# Error details

```
Error: у виборі філій має бути пошук

expect(received).toBeGreaterThan(expected)

Expected: > 0
Received:   0
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
      - link "Синхронізація MCP" [ref=f1e10] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=f1e11] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=f1e12]:
      - generic [ref=f1e13]: Адміністратор мережі
      - generic [ref=f1e14]: Супер-адміністратор
      - button "Вийти" [ref=f1e15] [cursor=pointer]
  - main [ref=f1e16]:
    - generic [ref=f1e17]:
      - navigation [ref=f1e19]:
        - link "Постачальники" [ref=f1e20] [cursor=pointer]:
          - /url: /suppliers
        - generic [ref=f1e21]: →
        - generic [ref=f1e22]: Додати постачальника
      - heading "Додати постачальника" [level=1] [ref=f1e24]
      - generic [ref=f1e25]:
        - generic [ref=f1e26]: Загальне
        - generic [ref=f1e27]:
          - generic [ref=f1e28]:
            - generic [ref=f1e29]: Назва
            - textbox "Назва" [ref=f1e30]
            - generic [ref=f1e31]: Назва обовʼязкова та має бути унікальною
          - generic [ref=f1e32]:
            - generic [ref=f1e33]: ЄДРПОУ
            - textbox "ЄДРПОУ" [ref=f1e34]
            - generic [ref=f1e35]: Необовʼязково; 8 або 10 цифр
          - generic [ref=f1e36]:
            - generic [ref=f1e37]: Контактна особа
            - textbox "Контактна особа" [ref=f1e38]
            - generic [ref=f1e39]: Імʼя контактної особи обовʼязкове
          - generic [ref=f1e40]:
            - generic [ref=f1e41]: Телефон
            - textbox "Телефон" [ref=f1e42]: "+380"
          - generic [ref=f1e43]:
            - generic [ref=f1e44]: E-mail
            - textbox "E-mail" [ref=f1e45]
        - button "Зберегти" [disabled] [ref=f1e47]
```

# Test source

```ts
  1   | /**
  2   |  * Повнота даних в інтерфейсі (перевірки X-01, X-02 з плану).
  3   |  *
  4   |  * Головне правило: очікуване значення береться з API, а не з голови автора.
  5   |  * Інакше тест закріпить рівно той зріз даних, який показує інтерфейс, і
  6   |  * пропустить саме той дефект, заради якого писався.
  7   |  */
  8   | import { expect, test } from '@playwright/test';
  9   | import { adminToken, api, CREDS, HOSTS, loginUi, pageText, supplierToken } from '../support/env';
  10  | 
  11  | test.describe('Повнота даних', () => {
  12  |   test('X-01 адмінка: список магазинів показує всі 455 філій', async ({ page }) => {
  13  |     const ctx = await api();
  14  |     const token = await adminToken(ctx);
  15  |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?perPage=20`, {
  16  |       headers: { Authorization: `Bearer ${token}` },
  17  |     });
  18  |     const expected = (await res.json()).total as number;
  19  | 
  20  |     await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
  21  |     await page.goto(HOSTS.admin + '/stores');
  22  |     await page.waitForLoadState('networkidle');
  23  | 
  24  |     const text = await pageText(page);
  25  |     expect(text, 'на сторінці має бути видно загальну кількість магазинів').toContain(String(expected));
  26  |   });
  27  | 
  28  |   test('X-01/X-02 адмінка: вибір філій постачальника бачить УСІ київські філії', async ({ page }) => {
  29  |     const ctx = await api();
  30  |     const token = await adminToken(ctx);
  31  | 
  32  |     // Скільки київських філій насправді.
  33  |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?city=${encodeURIComponent('Київ')}&perPage=100`, {
  34  |       headers: { Authorization: `Bearer ${token}` },
  35  |     });
  36  |     const body = await res.json();
  37  |     const kyivTotal = body.total as number;
  38  |     expect(kyivTotal, 'у Києві має бути більше 20 філій, інакше тест безсилий').toBeGreaterThan(20);
  39  | 
  40  |     await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
  41  |     await page.goto(HOSTS.admin + '/suppliers/new');
  42  |     await page.waitForLoadState('networkidle');
  43  | 
  44  |     // Скільки варіантів філій узагалі завантажив застосунок.
  45  |     const loaded = await page.evaluate(() => {
  46  |       const w = window as unknown as { __storeOptionsCount?: number };
  47  |       return w.__storeOptionsCount ?? null;
  48  |     });
  49  | 
  50  |     // Головна перевірка — через пошук: набираємо «Київ» і рахуємо знайдене.
  51  |     const searchBox = page.locator('input[type=search], input[placeholder*="ошук"], input[placeholder*="ілі"]').first();
  52  |     const hasSearch = await searchBox.count();
> 53  |     expect(hasSearch, 'у виборі філій має бути пошук').toBeGreaterThan(0);
      |                                                        ^ Error: у виборі філій має бути пошук
  54  | 
  55  |     await searchBox.fill('Київ');
  56  |     await page.waitForTimeout(1200);
  57  | 
  58  |     const optionsShown = await page.evaluate(() => {
  59  |       const nodes = document.querySelectorAll('[role=option], .option, li, label');
  60  |       return [...nodes].map((n) => (n as HTMLElement).innerText || '').filter((t) => t.includes('Київ')).length;
  61  |     });
  62  | 
  63  |     expect(
  64  |       optionsShown,
  65  |       `пошук «Київ» показав ${optionsShown} філій, а в базі їх ${kyivTotal}` +
  66  |         (loaded ? ` (застосунок завантажив лише ${loaded})` : ''),
  67  |     ).toBeGreaterThanOrEqual(kyivTotal);
  68  |   });
  69  | 
  70  |   test('X-01 кабінет постачальника: список міст повний', async ({ page }) => {
  71  |     const ctx = await api();
  72  |     const token = await supplierToken(ctx);
  73  |     const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/cities`, {
  74  |       headers: { Authorization: `Bearer ${token}` },
  75  |     });
  76  |     const cities = (await res.json()).items as { city: string; storeCount: number }[];
  77  | 
  78  |     await loginUi(page, HOSTS.supplier, { 'input[type=email]': CREDS.supplier.login, 'input[type=password]': CREDS.supplier.password });
  79  |     await page.goto(HOSTS.supplier + '/booking/cities');
  80  |     await page.waitForLoadState('networkidle');
  81  | 
  82  |     const text = await pageText(page);
  83  |     for (const c of cities) {
  84  |       expect(text, `місто ${c.city} має бути в списку`).toContain(c.city);
  85  |     }
  86  |   });
  87  | 
  88  |   test('X-01 кабінет постачальника: у місті видно всі активні філії', async ({ page }) => {
  89  |     const ctx = await api();
  90  |     const token = await supplierToken(ctx);
  91  |     const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Київ')}`, {
  92  |       headers: { Authorization: `Bearer ${token}` },
  93  |     });
  94  |     const stores = (await res.json()).items as { externalId: string }[];
  95  | 
  96  |     await loginUi(page, HOSTS.supplier, { 'input[type=email]': CREDS.supplier.login, 'input[type=password]': CREDS.supplier.password });
  97  |     await page.goto(HOSTS.supplier + '/booking/cities/' + encodeURIComponent('Київ'));
  98  |     await page.waitForLoadState('networkidle');
  99  | 
  100 |     const text = await pageText(page);
  101 |     const missing = stores.filter((s) => !text.includes(s.externalId)).map((s) => s.externalId);
  102 |     expect(missing, `філії, яких немає на екрані: ${missing.join(', ')}`).toHaveLength(0);
  103 |   });
  104 | 
  105 |   test('X-02 адмінка: пошук магазину з «дальньої» сторінки знаходить його', async ({ page }) => {
  106 |     const ctx = await api();
  107 |     const token = await adminToken(ctx);
  108 | 
  109 |     // Беремо філію з кінця повного списку — вона свідомо не на першій сторінці.
  110 |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?perPage=100&page=4`, {
  111 |       headers: { Authorization: `Bearer ${token}` },
  112 |     });
  113 |     const items = (await res.json()).items as { externalId: string; address: string }[];
  114 |     const target = items.find((i) => i.address && i.externalId);
  115 |     test.skip(!target, 'немає даних для перевірки');
  116 | 
  117 |     await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
  118 |     await page.goto(HOSTS.admin + '/stores');
  119 |     await page.waitForLoadState('networkidle');
  120 | 
  121 |     const search = page.locator('input[type=search], input[placeholder*="ошук"]').first();
  122 |     await search.fill(target!.externalId);
  123 |     await page.waitForTimeout(1500);
  124 | 
  125 |     const text = await pageText(page);
  126 |     expect(text, `пошук за externalId ${target!.externalId} має знайти філію`).toContain(target!.externalId);
  127 |   });
  128 | });
  129 | 
```