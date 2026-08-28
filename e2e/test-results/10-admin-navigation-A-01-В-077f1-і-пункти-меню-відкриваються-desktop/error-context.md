# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 10-admin-navigation.spec.ts >> A-01 Вхід і навігація >> A-01.5 усі пункти меню відкриваються
- Location: tests/10-admin-navigation.spec.ts:58:7

# Error details

```
Error: меню super_admin має містити всі чотири розділи

expect(received).toEqual(expected) // deep equality

- Expected  - 0
+ Received  + 1

  Array [
    "Магазини",
    "Постачальники",
+   "Користувачі",
    "Синхронізація MCP",
    "Аналітика",
  ]
```

# Page snapshot

```yaml
- generic [ref=e4]:
  - complementary [ref=e5]:
    - generic [ref=e6]: YMS «Рампа»
    - navigation [ref=e7]:
      - link "Магазини" [ref=e8] [cursor=pointer]:
        - /url: /stores
      - link "Постачальники" [ref=e9] [cursor=pointer]:
        - /url: /suppliers
      - link "Користувачі" [ref=e10] [cursor=pointer]:
        - /url: /users
      - link "Синхронізація MCP" [ref=e11] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=e12] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=e13]:
      - generic [ref=e14]: Адміністратор мережі
      - generic [ref=e15]: Супер-адміністратор
      - button "Вийти" [ref=e16] [cursor=pointer]
  - main [ref=e17]:
    - generic [ref=e18]:
      - heading "Магазини" [level=1] [ref=e20]
      - generic [ref=e21]:
        - generic [ref=e22]:
          - generic [ref=e23]: Пошук
          - searchbox "Пошук" [ref=e24]
        - generic [ref=e26]:
          - generic [ref=e27]: Місто
          - button "— ▾" [ref=e28] [cursor=pointer]:
            - generic [ref=e29]: —
            - generic [ref=e30]: ▾
        - generic [ref=e32]:
          - generic [ref=e33]: Статус YMS
          - button "— ▾" [ref=e34] [cursor=pointer]:
            - generic [ref=e35]: —
            - generic [ref=e36]: ▾
        - generic [ref=e37]:
          - generic [ref=e38]: Налаштованість
          - combobox "Налаштованість" [ref=e39]:
            - option "Будь-який" [selected]
            - option "Налаштовано"
            - option "Не налаштовано"
        - button "Застосувати" [ref=e40] [cursor=pointer]
      - generic [ref=e41]:
        - table [ref=e42]:
          - rowgroup [ref=e43]:
            - row [ref=e44]:
              - columnheader [ref=e45]:
                - checkbox "select-all" [ref=e46]
              - columnheader "Код філії" [ref=e47] [cursor=pointer]
              - columnheader "Назва для відображення" [ref=e48]
              - columnheader "Місто ↑" [ref=e49] [cursor=pointer]
              - columnheader "Адреса" [ref=e50] [cursor=pointer]
              - columnheader "Статус YMS" [ref=e51] [cursor=pointer]
              - columnheader "Налаштовано" [ref=e52]
              - columnheader "Рамп" [ref=e53]
              - columnheader "Макс. тоннаж, т" [ref=e54]
              - columnheader "Остання синхронізація" [ref=e55] [cursor=pointer]
          - rowgroup [ref=e56]:
            - row [ref=e57]:
              - cell [ref=e58]:
                - checkbox "2505" [ref=e59]
              - cell [ref=e60]:
                - link "2505" [ref=e61] [cursor=pointer]:
                  - /url: /stores/1edb7353-c9ea-6382-b36b-11a6c487168c
              - cell [ref=e62]
              - cell [ref=e63]
              - cell [ref=e64]
              - cell "Не налаштовано" [ref=e65]
              - cell "Ні" [ref=e67]
              - cell "0" [ref=e69]
              - cell "—" [ref=e70]
              - cell "28.08.2026, 02:53" [ref=e71]
            - row [ref=e72]:
              - cell [ref=e73]:
                - checkbox "3097" [ref=e74]
              - cell [ref=e75]:
                - link "3097" [ref=e76] [cursor=pointer]:
                  - /url: /stores/1edb7335-9721-69c8-8769-11a6c487168c
              - cell "вул. Яворівська, 30" [ref=e77]
              - cell [ref=e78]
              - cell "вул. Яворівська, 30" [ref=e79]
              - cell "Не налаштовано" [ref=e80]
              - cell "Ні" [ref=e82]
              - cell "0" [ref=e84]
              - cell "—" [ref=e85]
              - cell "28.08.2026, 02:53" [ref=e86]
            - row [ref=e87]:
              - cell [ref=e88]:
                - checkbox "3656" [ref=e89]
              - cell [ref=e90]:
                - link "3656" [ref=e91] [cursor=pointer]:
                  - /url: /stores/1eecbd44-a3ed-65fc-9ac4-c39702503ccc
              - cell [ref=e92]
              - cell [ref=e93]
              - cell [ref=e94]
              - cell "Не налаштовано" [ref=e95]
              - cell "Ні" [ref=e97]
              - cell "0" [ref=e99]
              - cell "—" [ref=e100]
              - cell "28.08.2026, 02:53" [ref=e101]
            - row [ref=e102]:
              - cell [ref=e103]:
                - checkbox "delete_filia_silpo_ferma_2286" [ref=e104]
              - cell [ref=e105]:
                - link "delete_filia_silpo_ferma_2286" [ref=e106] [cursor=pointer]:
                  - /url: /stores/1edb735e-e4f1-6936-ba95-a143e3aed11b
              - cell [ref=e107]
              - cell [ref=e108]
              - cell [ref=e109]
              - cell "Не налаштовано" [ref=e110]
              - cell "Ні" [ref=e112]
              - cell "0" [ref=e114]
              - cell "—" [ref=e115]
              - cell "28.08.2026, 02:53" [ref=e116]
            - row [ref=e117]:
              - cell [ref=e118]:
                - checkbox "delete_filia_silpo_ferma_2287" [ref=e119]
              - cell [ref=e120]:
                - link "delete_filia_silpo_ferma_2287" [ref=e121] [cursor=pointer]:
                  - /url: /stores/1edb735e-7a82-64e2-ac3c-f77053673ad9
              - cell [ref=e122]
              - cell [ref=e123]
              - cell [ref=e124]
              - cell "Не налаштовано" [ref=e125]
              - cell "Ні" [ref=e127]
              - cell "0" [ref=e129]
              - cell "—" [ref=e130]
              - cell "28.08.2026, 02:53" [ref=e131]
            - row [ref=e132]:
              - cell [ref=e133]:
                - checkbox "delete_filia_silpo_ivasuka46" [ref=e134]
              - cell [ref=e135]:
                - link "delete_filia_silpo_ivasuka46" [ref=e136] [cursor=pointer]:
                  - /url: /stores/1edb2828-37e2-6690-af0a-5f4f054120bc
              - cell [ref=e137]
              - cell [ref=e138]
              - cell [ref=e139]
              - cell "Не налаштовано" [ref=e140]
              - cell "Ні" [ref=e142]
              - cell "0" [ref=e144]
              - cell "—" [ref=e145]
              - cell "28.08.2026, 02:53" [ref=e146]
            - row [ref=e147]:
              - cell [ref=e148]:
                - checkbox "delete_filia_silpo_nerejanskaya22" [ref=e149]
              - cell [ref=e150]:
                - link "delete_filia_silpo_nerejanskaya22" [ref=e151] [cursor=pointer]:
                  - /url: /stores/1edb6b29-08c1-667e-bcb8-d9341fb2cc7b
              - cell [ref=e152]
              - cell [ref=e153]
              - cell [ref=e154]
              - cell "Не налаштовано" [ref=e155]
              - cell "Ні" [ref=e157]
              - cell "0" [ref=e159]
              - cell "—" [ref=e160]
              - cell "28.08.2026, 02:53" [ref=e161]
            - row [ref=e162]:
              - cell [ref=e163]:
                - checkbox "delete_filia_silpo_stalingrad46" [ref=e164]
              - cell [ref=e165]:
                - link "delete_filia_silpo_stalingrad46" [ref=e166] [cursor=pointer]:
                  - /url: /stores/1edb6b1a-c102-6eae-9f91-0f4ab5c79679
              - cell [ref=e167]
              - cell [ref=e168]
              - cell [ref=e169]
              - cell "Не налаштовано" [ref=e170]
              - cell "Ні" [ref=e172]
              - cell "0" [ref=e174]
              - cell "—" [ref=e175]
              - cell "28.08.2026, 02:53" [ref=e176]
            - row [ref=e177]:
              - cell [ref=e178]:
                - checkbox "2116" [ref=e179]
              - cell [ref=e180]:
                - link "2116" [ref=e181] [cursor=pointer]:
                  - /url: /stores/1edb6b5a-55fb-6864-9a0f-d54e0a9fe643
              - cell "вул. Мазепи, 168А" [ref=e182]
              - cell "Івано-Франківськ" [ref=e183]
              - cell "вул. Мазепи, 168А" [ref=e184]
              - cell "Не налаштовано" [ref=e185]
              - cell "Ні" [ref=e187]
              - cell "0" [ref=e189]
              - cell "—" [ref=e190]
              - cell "28.08.2026, 02:53" [ref=e191]
            - row [ref=e192]:
              - cell [ref=e193]:
                - checkbox "2117" [ref=e194]
              - cell [ref=e195]:
                - link "2117" [ref=e196] [cursor=pointer]:
                  - /url: /stores/1edb6b5a-b1b0-611e-a929-d11f2666a570
              - cell "вул. Дністровська, 3" [ref=e197]
              - cell "Івано-Франківськ" [ref=e198]
              - cell "вул. Дністровська, 3" [ref=e199]
              - cell "Не налаштовано" [ref=e200]
              - cell "Ні" [ref=e202]
              - cell "0" [ref=e204]
              - cell "—" [ref=e205]
              - cell "28.08.2026, 02:53" [ref=e206]
            - row [ref=e207]:
              - cell [ref=e208]:
                - checkbox "2118" [ref=e209]
              - cell [ref=e210]:
                - link "2118" [ref=e211] [cursor=pointer]:
                  - /url: /stores/1edb6b5b-1b9a-6ce6-bb85-639d81d4aac4
              - cell "бульв. Північний, 2А" [ref=e212]
              - cell "Івано-Франківськ" [ref=e213]
              - cell "бульв. Північний, 2А" [ref=e214]
              - cell "Не налаштовано" [ref=e215]
              - cell "Ні" [ref=e217]
              - cell "0" [ref=e219]
              - cell "—" [ref=e220]
              - cell "28.08.2026, 02:53" [ref=e221]
            - row [ref=e222]:
              - cell [ref=e223]:
                - checkbox "3976" [ref=e224]
              - cell [ref=e225]:
                - link "3976" [ref=e226] [cursor=pointer]:
                  - /url: /stores/1ef9b801-5831-6216-99a5-fd246a208e47
              - cell "вул. Мазепи, 168А" [ref=e227]
              - cell "Івано-Франківськ" [ref=e228]
              - cell "вул. Мазепи, 168А" [ref=e229]
              - cell "Не налаштовано" [ref=e230]
              - cell "Ні" [ref=e232]
              - cell "0" [ref=e234]
              - cell "—" [ref=e235]
              - cell "28.08.2026, 02:53" [ref=e236]
            - row [ref=e237]:
              - cell [ref=e238]:
                - checkbox "2966" [ref=e239]
              - cell [ref=e240]:
                - link "2966" [ref=e241] [cursor=pointer]:
                  - /url: /stores/1edb733d-b42b-64ee-b5d5-73524574f50b
              - cell "вул. Літературна, 27" [ref=e242]
              - cell "Ірпінь" [ref=e243]
              - cell "вул. Літературна, 27" [ref=e244]
              - cell "Не налаштовано" [ref=e245]
              - cell "Ні" [ref=e247]
              - cell "0" [ref=e249]
              - cell "—" [ref=e250]
              - cell "28.08.2026, 02:53" [ref=e251]
            - row [ref=e252]:
              - cell [ref=e253]:
                - checkbox "3259" [ref=e254]
              - cell [ref=e255]:
                - link "3259" [ref=e256] [cursor=pointer]:
                  - /url: /stores/1f0dcc8f-0f9f-6a6e-a05e-c38d9b34c11f
              - cell "вул. Сковороди, 8" [ref=e257]
              - cell "Ірпінь" [ref=e258]
              - cell "вул. Сковороди, 8" [ref=e259]
              - cell "Не налаштовано" [ref=e260]
              - cell "Ні" [ref=e262]
              - cell "0" [ref=e264]
              - cell "—" [ref=e265]
              - cell "28.08.2026, 02:53" [ref=e266]
            - row [ref=e267]:
              - cell [ref=e268]:
                - checkbox "3891" [ref=e269]
              - cell [ref=e270]:
                - link "3891" [ref=e271] [cursor=pointer]:
                  - /url: /stores/1efb6e1e-aa8f-604c-988a-27f1dec9eef8
              - cell "вул. Соборна, 160" [ref=e272]
              - cell "Ірпінь" [ref=e273]
              - cell "вул. Соборна, 160" [ref=e274]
              - cell "Не налаштовано" [ref=e275]
              - cell "Ні" [ref=e277]
              - cell "0" [ref=e279]
              - cell "—" [ref=e280]
              - cell "28.08.2026, 02:53" [ref=e281]
            - row [ref=e282]:
              - cell [ref=e283]:
                - checkbox "3905" [ref=e284]
              - cell [ref=e285]:
                - link "3905" [ref=e286] [cursor=pointer]:
                  - /url: /stores/1efb6e15-b171-6ba2-ba6f-e90a6eb5ec15
              - cell "вул. Соборна, 160" [ref=e287]
              - cell "Ірпінь" [ref=e288]
              - cell "вул. Соборна, 160" [ref=e289]
              - cell "Не налаштовано" [ref=e290]
              - cell "Ні" [ref=e292]
              - cell "0" [ref=e294]
              - cell "—" [ref=e295]
              - cell "28.08.2026, 02:53" [ref=e296]
            - row [ref=e297]:
              - cell [ref=e298]:
                - checkbox "1997" [ref=e299]
              - cell [ref=e300]:
                - link "1997" [ref=e301] [cursor=pointer]:
                  - /url: /stores/1edb6b1a-626d-64ca-9046-0b7012e7f9f8
              - cell "вул. Київський Шлях, 76" [ref=e302]
              - cell "Бориспіль" [ref=e303]
              - cell "вул. Київський Шлях, 76" [ref=e304]
              - cell "Не налаштовано" [ref=e305]
              - cell "Ні" [ref=e307]
              - cell "0" [ref=e309]
              - cell "—" [ref=e310]
              - cell "28.08.2026, 02:53" [ref=e311]
            - row [ref=e312]:
              - cell [ref=e313]:
                - checkbox "3190" [ref=e314]
              - cell [ref=e315]:
                - link "3190" [ref=e316] [cursor=pointer]:
                  - /url: /stores/1edb7319-118e-6778-8404-6fea04bfe766
              - cell "вул. Київський Шлях, 67" [ref=e317]
              - cell "Бориспіль" [ref=e318]
              - cell "вул. Київський Шлях, 67" [ref=e319]
              - cell "Не налаштовано" [ref=e320]
              - cell "Ні" [ref=e322]
              - cell "0" [ref=e324]
              - cell "—" [ref=e325]
              - cell "28.08.2026, 02:53" [ref=e326]
            - row [ref=e327]:
              - cell [ref=e328]:
                - checkbox "3436" [ref=e329]
              - cell [ref=e330]:
                - link "3436" [ref=e331] [cursor=pointer]:
                  - /url: /stores/1edf3108-fb26-610a-8e0a-d980ecba6063
              - cell "вул. Київський Шлях, 6" [ref=e332]
              - cell "Бориспіль" [ref=e333]
              - cell "вул. Київський Шлях, 6" [ref=e334]
              - cell "Не налаштовано" [ref=e335]
              - cell "Ні" [ref=e337]
              - cell "0" [ref=e339]
              - cell "—" [ref=e340]
              - cell "28.08.2026, 02:53" [ref=e341]
            - row [ref=e342]:
              - cell [ref=e343]:
                - checkbox "4204" [ref=e344]
              - cell [ref=e345]:
                - link "4204" [ref=e346] [cursor=pointer]:
                  - /url: /stores/1f071d01-27ef-61e4-bd93-f981fceb620c
              - cell "вул. Київський шлях, 6" [ref=e347]
              - cell "Бориспіль" [ref=e348]
              - cell "вул. Київський шлях, 6" [ref=e349]
              - cell "Не налаштовано" [ref=e350]
              - cell "Ні" [ref=e352]
              - cell "0" [ref=e354]
              - cell "—" [ref=e355]
              - cell "28.08.2026, 02:53" [ref=e356]
        - generic [ref=e358]:
          - generic [ref=e359]: "Усього: 455"
          - generic [ref=e360]:
            - text: Рядків на сторінці
            - combobox "page-size" [ref=e361]:
              - option "20" [selected]
              - option "50"
              - option "100"
          - button "‹" [disabled] [ref=e362]
          - generic [ref=e363]: Сторінка 1 з 23
          - button "›" [ref=e364] [cursor=pointer]
```

# Test source

```ts
  1   | /**
  2   |  * A-01. Вхід і навігація адмін-панелі + наскрізні перевірки X-07, X-09, X-10.
  3   |  */
  4   | import { expect, test } from '@playwright/test';
  5   | import { CREDS } from '../support/env';
  6   | import {
  7   |   ADMIN,
  8   |   bodyText,
  9   |   goto,
  10  |   loginAdmin,
  11  |   untranslated,
  12  | } from '../support/admin';
  13  | 
  14  | test.describe('A-01 Вхід і навігація', () => {
  15  |   test('A-01.1 валідні дані ведуть на список магазинів', async ({ page }) => {
  16  |     await loginAdmin(page);
  17  |     expect(page.url(), 'після входу має відкритись розділ за замовчуванням').toContain('/stores');
  18  |     await expect(page.locator('h1')).toHaveText('Магазини');
  19  |     await expect(page.locator('.sidebar-user')).not.toBeEmpty();
  20  |   });
  21  | 
  22  |   test('A-01.2 невалідний пароль — повідомлення, вхід не відбувається', async ({ page }) => {
  23  |     await page.goto(`${ADMIN}/login`);
  24  |     await page.waitForSelector('#password');
  25  |     await page.locator('#email').fill(CREDS.admin.email);
  26  |     await page.locator('#password').fill('ЦеТочноНеПароль123');
  27  |     await Promise.all([
  28  |       page.waitForResponse((r) => r.url().includes('/auth/login')),
  29  |       page.locator('button[type=submit]').click(),
  30  |     ]);
  31  | 
  32  |     const notice = page.locator('.notice-danger');
  33  |     await expect(notice, 'помилка входу має бути видимою').toBeVisible();
  34  |     await expect(notice).toContainText('Невірний e-mail або пароль');
  35  |     expect(page.url(), 'користувач лишається на сторінці входу').toContain('/login');
  36  |   });
  37  | 
  38  |   test('A-01.3 порожні поля — зрозуміла відмова без запиту на сервер', async ({ page }) => {
  39  |     await page.goto(`${ADMIN}/login`);
  40  |     await page.waitForSelector('#password');
  41  |     await page.locator('#email').fill('');
  42  |     await page.locator('#password').fill('');
  43  |     await page.locator('button[type=submit]').click();
  44  |     await expect(page.locator('.notice-danger')).toContainText('Заповніть e-mail і пароль');
  45  |   });
  46  | 
  47  |   test('A-01.4 X-09 прямий перехід без токена веде на вхід', async ({ page }) => {
  48  |     for (const path of ['/stores', '/suppliers', '/mcp-sync', '/analytics']) {
  49  |       await page.goto(ADMIN + path);
  50  |       await page.waitForURL(/\/login/, { timeout: 15_000 });
  51  |       expect(page.url(), `${path} без токена має вести на /login`).toContain('/login');
  52  |       expect(page.url(), 'адреса, куди хотів потрапити користувач, має зберігатись').toContain(
  53  |         'redirect',
  54  |       );
  55  |     }
  56  |   });
  57  | 
  58  |   test('A-01.5 усі пункти меню відкриваються', async ({ page }) => {
  59  |     await loginAdmin(page);
  60  | 
  61  |     const expected = [
  62  |       { label: 'Магазини', url: '/stores', heading: 'Магазини' },
  63  |       { label: 'Постачальники', url: '/suppliers', heading: 'Постачальники' },
  64  |       { label: 'Синхронізація MCP', url: '/mcp-sync', heading: 'Синхронізація MCP' },
  65  |       { label: 'Аналітика', url: '/analytics', heading: 'Аналітика' },
  66  |     ];
  67  | 
  68  |     const links = await page.locator('.sidebar-link').allInnerTexts();
> 69  |     expect(links.map((s) => s.trim()), 'меню super_admin має містити всі чотири розділи').toEqual(
      |                                                                                           ^ Error: меню super_admin має містити всі чотири розділи
  70  |       expected.map((e) => e.label),
  71  |     );
  72  | 
  73  |     for (const item of expected) {
  74  |       await page.locator('.sidebar-link', { hasText: item.label }).click();
  75  |       await page.waitForURL(new RegExp(item.url.replace('/', '\\/')), { timeout: 15_000 });
  76  |       await page.waitForLoadState('networkidle');
  77  |       await expect(page.locator('h1'), `розділ ${item.label} має відкритись`).toHaveText(
  78  |         item.heading,
  79  |       );
  80  |     }
  81  |   });
  82  | 
  83  |   test('A-01.6 вихід завершує сесію', async ({ page }) => {
  84  |     await loginAdmin(page);
  85  |     await page.locator('.sidebar-footer button', { hasText: 'Вийти' }).click();
  86  |     await page.waitForURL(/\/login/, { timeout: 15_000 });
  87  | 
  88  |     await page.goto(`${ADMIN}/stores`);
  89  |     await page.waitForURL(/\/login/, { timeout: 15_000 });
  90  |     expect(page.url(), 'після виходу захищені розділи недоступні').toContain('/login');
  91  |   });
  92  | 
  93  |   test('A-01.7 X-07 інтерфейс українською, без неперекладених ключів', async ({ page }) => {
  94  |     await loginAdmin(page);
  95  |     const found: Record<string, string[]> = {};
  96  |     for (const path of ['/stores', '/suppliers', '/mcp-sync', '/analytics']) {
  97  |       await goto(page, path);
  98  |       const leftovers = untranslated(await bodyText(page));
  99  |       if (leftovers.length > 0) {
  100 |         found[path] = leftovers;
  101 |       }
  102 |     }
  103 |     expect(found, `неперекладені ключі: ${JSON.stringify(found)}`).toEqual({});
  104 |   });
  105 | 
  106 |   test('A-01.8 X-10 адаптивність 360 / 768 / 1280 без горизонтального скролу', async ({ page }) => {
  107 |     await loginAdmin(page);
  108 |     const broken: string[] = [];
  109 |     for (const width of [360, 768, 1280]) {
  110 |       await page.setViewportSize({ width, height: 900 });
  111 |       for (const path of ['/stores', '/suppliers', '/mcp-sync', '/analytics']) {
  112 |         await goto(page, path);
  113 |         const overflow = await page.evaluate(
  114 |           () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  115 |         );
  116 |         if (overflow > 1) {
  117 |           broken.push(`${path} @${width}px: зайвих ${overflow}px`);
  118 |         }
  119 |       }
  120 |     }
  121 |     expect(broken, `горизонтальний скрол: ${broken.join('; ')}`).toEqual([]);
  122 |   });
  123 | });
  124 | 
```