# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 13-admin-store-limits.spec.ts >> A-06 Вкладка «Обмеження» >> A-06.3 тоннаж: 0.5, 45 і крок 12.3 відхиляються
- Location: tests/13-admin-store-limits.spec.ts:72:7

# Error details

```
Error: тоннаж 0.5 має бути відхилений

expect(locator).toContainText(expected) failed

Locator: locator('.field-error')
Expected substring: "Тоннаж — від 1.0 до 40.0 з кроком 0.5"
Error: strict mode violation: locator('.field-error') resolved to 2 elements:
    1) <div class="field-error">Тоннаж — від 1.0 до 40.0 з кроком 0.5</div> aka locator('app-store-limits-tab').getByText('Тоннаж — від 1.0 до 40.0')
    2) <div class="field-error">Тоннаж — від 1.0 до 40.0 з кроком 0.5</div> aka getByText('Тоннаж — від 1.0 до 40.0').nth(1)

Call log:
  - тоннаж 0.5 має бути відхилений with timeout 15000ms
  - waiting for locator('.field-error')

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
      - navigation [ref=f1e20]:
        - link "Магазини" [ref=f1e21] [cursor=pointer]:
          - /url: /stores
        - generic [ref=f1e22]: →
        - link "Харків, філія 2230" [ref=f1e23] [cursor=pointer]:
          - /url: /stores/1edb6b7b-7054-6186-9f70-876a056bdc57
        - generic [ref=f1e24]: →
        - generic [ref=f1e25]: Обмеження
      - generic [ref=f1e27]:
        - heading "Сільпо, просп. Героїв Харкова, 256" [level=1] [ref=f1e28]
        - generic [ref=f1e29]:
          - generic [ref=f1e30]: "2230"
          - generic [ref=f1e31]: ·
          - generic [ref=f1e32]: Харків
          - generic [ref=f1e33]: ·
          - generic [ref=f1e34]: просп. Героїв Харкова, 256
      - generic [ref=f1e35]:
        - button "Загальне" [ref=f1e36] [cursor=pointer]
        - button "Прийом поставок" [ref=f1e37] [cursor=pointer]
        - button "Слоти" [ref=f1e38] [cursor=pointer]
        - button "Обмеження" [ref=f1e39] [cursor=pointer]
        - button "Резерви" [ref=f1e40] [cursor=pointer]
        - button "Блокування слотів" [ref=f1e41] [cursor=pointer]
      - generic [ref=f1e43]:
        - generic [ref=f1e44]: Обмеження
        - generic [ref=f1e45]:
          - generic [ref=f1e46]:
            - generic [ref=f1e47]: Максимальний тоннаж авто, т
            - spinbutton "Максимальний тоннаж авто, т" [active] [ref=f1e48]: "0.5"
            - generic [ref=f1e49]: Від 1.0 до 40.0 з кроком 0.5
            - generic [ref=f1e50]: Тоннаж — від 1.0 до 40.0 з кроком 0.5
          - generic [ref=f1e51]:
            - generic [ref=f1e52]: Lead time, хвилин
            - spinbutton "Lead time, хвилин" [ref=f1e53]: "60"
            - generic [ref=f1e54]: Мінімальний час до початку слоту, у хвилинах
          - generic [ref=f1e55]:
            - generic [ref=f1e56]: Горизонт бронювання, днів
            - spinbutton "Горизонт бронювання, днів" [ref=f1e57]: "14"
          - generic [ref=f1e58]:
            - generic [ref=f1e59]: Пільговий час до no-show, хв
            - spinbutton "Пільговий час до no-show, хв" [ref=f1e60]: "30"
            - generic [ref=f1e61]: Скільки чекати авто після початку слоту
          - generic [ref=f1e62]:
            - generic [ref=f1e63]: Максимальне утримання слоту, хв
            - spinbutton "Максимальне утримання слоту, хв" [ref=f1e64]: "15"
            - generic [ref=f1e65]: Скільки живе hold слоту в кабінеті постачальника
        - generic [ref=f1e66]: Зменшення тоннажу може зачепити вже наявні бронювання
      - generic [ref=f1e68]:
        - generic [ref=f1e69]:
          - generic [ref=f1e70]: Набирає чинності з
          - textbox "Набирає чинності з" [ref=f1e71]: 2026-08-29
          - generic [ref=f1e72]: Не раніше завтра; для ПЕРШОЇ версії конфігурації допускається сьогодні
          - generic [ref=f1e73]: Тоннаж — від 1.0 до 40.0 з кроком 0.5
        - button "Зберегти" [disabled] [ref=f1e74]
```

# Test source

```ts
  1   | /**
  2   |  * A-06 «Обмеження», A-07 «Резерви», A-08 «Блокування», A-09 конфлікти конфігурації.
  3   |  *
  4   |  * Межі значень звіряються з бекендом (store-service/StoreConfiguration):
  5   |  * тоннаж 1.0–40.0 крок 0.5, lead time 0–1440 хв, горизонт 1–30 днів,
  6   |  * пільговий час 0–240 хв, утримання слоту 1–60 хв.
  7   |  */
  8   | import { expect, test } from '@playwright/test';
  9   | import {
  10  |   apiGet,
  11  |   apiRaw,
  12  |   apiSuppliers,
  13  |   fieldErrors,
  14  |   goto,
  15  |   kyivDay,
  16  |   loginAdmin,
  17  |   openTab,
  18  |   sandboxStore,
  19  |   track,
  20  |   waitForToast,
  21  | } from '../support/admin';
  22  | 
  23  | /** Межі, які насправді приймає бекенд (джерело: StoreConfiguration::*). */
  24  | const BACKEND_LIMITS = {
  25  |   weight: { min: 1, max: 40, step: 0.5 },
  26  |   leadTimeMinutes: { min: 0, max: 1440 },
  27  |   bookingHorizonDays: { min: 1, max: 30 },
  28  |   noShowGraceMinutes: { min: 0, max: 240 },
  29  |   holdMaxMinutes: { min: 1, max: 60 },
  30  | };
  31  | 
  32  | test.beforeEach(async ({ page }) => {
  33  |   await loginAdmin(page);
  34  | });
  35  | 
  36  | async function openLimits(page: import('@playwright/test').Page, externalId: string) {
  37  |   const store = await sandboxStore(externalId);
  38  |   await goto(page, `/stores/${store.branchId}`);
  39  |   await page.locator('.tabs').waitFor({ state: 'visible', timeout: 20_000 });
  40  |   await openTab(page, 'Обмеження');
  41  |   return store;
  42  | }
  43  | 
  44  | /** Дата у поясі магазину: саме її очікують поля «Набирає чинності з» тощо. */
  45  | const dayOffset = kyivDay;
  46  | 
  47  | // ------------------------------------------------------------------ A-06
  48  | 
  49  | test.describe('A-06 Вкладка «Обмеження»', () => {
  50  |   test('A-06.1 поля заповнені значеннями чинної конфігурації', async ({ page }) => {
  51  |     const store = await openLimits(page, '2230');
  52  |     const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
  53  | 
  54  |     await expect(page.locator('#max-weight')).toHaveValue(String(config.maxVehicleWeightTons));
  55  |     await expect(page.locator('#lead-time')).toHaveValue(String(config.leadTimeMinutes));
  56  |     await expect(page.locator('#horizon')).toHaveValue(String(config.bookingHorizonDays));
  57  |     await expect(page.locator('#no-show-grace')).toHaveValue(String(config.noShowGraceMinutes));
  58  |     await expect(page.locator('#hold-max')).toHaveValue(String(config.holdMaxMinutes));
  59  |   });
  60  | 
  61  |   test('A-06.2 тоннаж: 1.0 і 40.0 приймаються', async ({ page }) => {
  62  |     await openLimits(page, '2230');
  63  |     for (const value of ['1', '1.0', '40', '40.0', '20.5']) {
  64  |       await page.locator('#max-weight').fill(value);
  65  |       expect(
  66  |         (await fieldErrors(page)).filter((e) => e.includes('Тоннаж')),
  67  |         `тоннаж ${value} має прийматись`,
  68  |       ).toEqual([]);
  69  |     }
  70  |   });
  71  | 
  72  |   test('A-06.3 тоннаж: 0.5, 45 і крок 12.3 відхиляються', async ({ page }) => {
  73  |     await openLimits(page, '2230');
  74  |     for (const value of ['0.5', '45', '12.3', '0', '40.5']) {
  75  |       await page.locator('#max-weight').fill(value);
  76  |       await expect(
  77  |         page.locator('.field-error'),
  78  |         `тоннаж ${value} має бути відхилений`,
> 79  |       ).toContainText('Тоннаж — від 1.0 до 40.0 з кроком 0.5');
      |         ^ Error: тоннаж 0.5 має бути відхилений
  80  |     }
  81  |   });
  82  | 
  83  |   test('A-06.4 атрибути поля тоннажу відповідають межам бекенду', async ({ page }) => {
  84  |     await openLimits(page, '2230');
  85  |     const field = page.locator('#max-weight');
  86  |     await expect(field).toHaveAttribute('min', String(BACKEND_LIMITS.weight.min));
  87  |     await expect(field).toHaveAttribute('max', String(BACKEND_LIMITS.weight.max));
  88  |     await expect(field).toHaveAttribute('step', String(BACKEND_LIMITS.weight.step));
  89  |   });
  90  | 
  91  |   test('A-06.5 lead time: 0 і 1440 приймаються', async ({ page }) => {
  92  |     await openLimits(page, '2230');
  93  |     for (const value of ['0', '1440', '60']) {
  94  |       await page.locator('#lead-time').fill(value);
  95  |       expect(
  96  |         (await fieldErrors(page)).filter((e) => e.includes('Lead time')),
  97  |         `lead time ${value} має прийматись`,
  98  |       ).toEqual([]);
  99  |     }
  100 |   });
  101 | 
  102 |   test('A-06.6 lead time поза межами бекенду (1441) відхиляється формою', async ({ page }) => {
  103 |     await openLimits(page, '2230');
  104 |     await page.locator('#lead-time').fill('1441');
  105 |     const errors = await fieldErrors(page);
  106 |     expect(
  107 |       errors.some((e) => e.includes('Lead time')),
  108 |       `1441 хв бекенд не приймає (діапазон 0–1440), форма має відхилити значення одразу; ` +
  109 |         `фактичні помилки на екрані: ${JSON.stringify(errors)}`,
  110 |     ).toBe(true);
  111 |   });
  112 | 
  113 |   test('A-06.7 атрибути поля lead time відповідають межам бекенду', async ({ page }) => {
  114 |     await openLimits(page, '2230');
  115 |     const field = page.locator('#lead-time');
  116 |     await expect(field).toHaveAttribute('min', String(BACKEND_LIMITS.leadTimeMinutes.min));
  117 |     await expect(
  118 |       field,
  119 |       'бекенд приймає максимум 1440 хв — саме це має стояти в полі',
  120 |     ).toHaveAttribute('max', String(BACKEND_LIMITS.leadTimeMinutes.max));
  121 |   });
  122 | 
  123 |   test('A-06.8 горизонт: 1 і 30 приймаються', async ({ page }) => {
  124 |     await openLimits(page, '2230');
  125 |     for (const value of ['1', '30', '14']) {
  126 |       await page.locator('#horizon').fill(value);
  127 |       expect(
  128 |         (await fieldErrors(page)).filter((e) => e.includes('Горизонт')),
  129 |         `горизонт ${value} має прийматись`,
  130 |       ).toEqual([]);
  131 |     }
  132 |   });
  133 | 
  134 |   test('A-06.9 горизонт поза межами бекенду (31 і 0) відхиляється формою', async ({ page }) => {
  135 |     await openLimits(page, '2230');
  136 |     await page.locator('#horizon').fill('0');
  137 |     expect(
  138 |       (await fieldErrors(page)).some((e) => e.includes('Горизонт')),
  139 |       'горизонт 0 днів має бути відхилений',
  140 |     ).toBe(true);
  141 | 
  142 |     await page.locator('#horizon').fill('31');
  143 |     const errors = await fieldErrors(page);
  144 |     expect(
  145 |       errors.some((e) => e.includes('Горизонт')),
  146 |       `бекенд приймає горизонт 1–30 днів, 31 має бути відхилено формою; ` +
  147 |         `фактичні помилки на екрані: ${JSON.stringify(errors)}`,
  148 |     ).toBe(true);
  149 |   });
  150 | 
  151 |   test('A-06.10 атрибути поля горизонту відповідають межам бекенду', async ({ page }) => {
  152 |     await openLimits(page, '2230');
  153 |     const field = page.locator('#horizon');
  154 |     await expect(field).toHaveAttribute('min', String(BACKEND_LIMITS.bookingHorizonDays.min));
  155 |     await expect(
  156 |       field,
  157 |       'бекенд приймає максимум 30 днів — саме це має стояти в полі',
  158 |     ).toHaveAttribute('max', String(BACKEND_LIMITS.bookingHorizonDays.max));
  159 |   });
  160 | 
  161 |   test('A-06.11 утримання слоту поза межами бекенду (61) відхиляється формою', async ({ page }) => {
  162 |     await openLimits(page, '2230');
  163 |     const field = page.locator('#hold-max');
  164 |     await expect(
  165 |       field,
  166 |       'бекенд приймає максимум 60 хв утримання слоту',
  167 |     ).toHaveAttribute('max', String(BACKEND_LIMITS.holdMaxMinutes.max));
  168 | 
  169 |     await field.fill('61');
  170 |     expect(
  171 |       (await fieldErrors(page)).some((e) => e.includes('Утримання слоту')),
  172 |       'утримання слоту 61 хв бекенд не приймає',
  173 |     ).toBe(true);
  174 |   });
  175 | 
  176 |   test('A-06.12 пільговий час: межі 0 і 240, за межами — відмова', async ({ page }) => {
  177 |     await openLimits(page, '2230');
  178 |     for (const value of ['0', '240']) {
  179 |       await page.locator('#no-show-grace').fill(value);
```