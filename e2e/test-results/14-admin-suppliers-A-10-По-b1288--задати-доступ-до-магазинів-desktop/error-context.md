# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 14-admin-suppliers.spec.ts >> A-10 Постачальники >> A-10.19 форма створення дозволяє одразу задати доступ до магазинів
- Location: tests/14-admin-suppliers.spec.ts:470:7

# Error details

```
Error: режим доступу до магазинів — частина заведення контрагента, інакше новий постачальник до першого редагування має доступ за замовчуванням

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
      - navigation [ref=f1e20]:
        - link "Постачальники" [ref=f1e21] [cursor=pointer]:
          - /url: /suppliers
        - generic [ref=f1e22]: →
        - generic [ref=f1e23]: Додати постачальника
      - heading "Додати постачальника" [level=1] [ref=f1e25]
      - generic [ref=f1e26]:
        - button "Загальне" [ref=f1e27] [cursor=pointer]
        - button "Магазини" [ref=f1e28] [cursor=pointer]
      - generic [ref=f1e29]:
        - generic [ref=f1e30]: Загальне
        - generic [ref=f1e31]:
          - generic [ref=f1e32]:
            - generic [ref=f1e33]: Назва
            - textbox "Назва" [ref=f1e34]
            - generic [ref=f1e35]: Назва обовʼязкова та має бути унікальною
          - generic [ref=f1e36]:
            - generic [ref=f1e37]: ЄДРПОУ
            - textbox "ЄДРПОУ" [ref=f1e38]
            - generic [ref=f1e39]: Необовʼязково; 8 або 10 цифр
          - generic [ref=f1e40]:
            - generic [ref=f1e41]: Контактна особа
            - textbox "Контактна особа" [ref=f1e42]
            - generic [ref=f1e43]: Імʼя контактної особи обовʼязкове
          - generic [ref=f1e44]:
            - generic [ref=f1e45]: Телефон
            - textbox "Телефон" [ref=f1e46]: "+380"
          - generic [ref=f1e47]:
            - generic [ref=f1e48]: E-mail
            - textbox "E-mail" [ref=f1e49]
        - button "Зберегти" [disabled] [ref=f1e51]
```

# Test source

```ts
  377 |     expect(saved.storeAccess.storeIds.length, 'обраний магазин збережено').toBe(1);
  378 | 
  379 |     // назад на «усі магазини»
  380 |     await goto(page, `/suppliers/${id}`);
  381 |     await openTab(page, 'Магазини');
  382 |     await page.locator('#access-mode').selectOption('all');
  383 |     await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
  384 |     await waitForToast(page);
  385 |     const back = await apiGet<any>(`/suppliers/${id}`);
  386 |     expect(back.storeAccess.allStores, 'повернулись до «усі магазини»').toBe(true);
  387 |   });
  388 | 
  389 |   test('A-10.16 X-01 у виборі магазинів доступні всі придатні філії мережі', async ({ page }) => {
  390 |     // Придатні = ті, що взагалі можна показати: з містом і адресою.
  391 |     // Записи MCP без міста застосунок свідомо ховає, тож еталон рахуємо так само.
  392 |     const all = await apiStores('perPage=100&page=1');
  393 |     const usable: number = await (async () => {
  394 |       let count = 0;
  395 |       const pages = Math.ceil(all.total / 100);
  396 |       for (let p = 1; p <= pages; p += 1) {
  397 |         const chunk = await apiStores(`perPage=100&page=${p}`);
  398 |         count += chunk.items.filter((i) => i.city?.trim() && i.address?.trim()).length;
  399 |       }
  400 |       return count;
  401 |     })();
  402 |     const total = usable;
  403 | 
  404 |     const id = await createSupplier(page, {
  405 |       name: testSupplierName('повнота'),
  406 |       edrpou: nextTestEdrpou(),
  407 |       contact: 'UITEST',
  408 |       phone: '+380501117711',
  409 |       email: 'uitest.full@rampa.test',
  410 |     });
  411 | 
  412 |     await goto(page, `/suppliers/${id}`);
  413 |     await openTab(page, 'Магазини');
  414 |     await page.locator('#access-mode').selectOption('whitelist');
  415 | 
  416 |     const options = await multiSelectOptions(page, 'Магазини');
  417 |     expect(
  418 |       options.length,
  419 |       `у виборі філій видно ${options.length} варіантів, а придатних філій у мережі ${total}: ` +
  420 |         'решту неможливо ані побачити, ані обрати без пошуку',
  421 |     ).toBeGreaterThanOrEqual(total);
  422 |   });
  423 | 
  424 |   test('A-10.17 X-02 пошук «Київ» у виборі магазинів знаходить усі київські філії', async ({
  425 |     page,
  426 |   }) => {
  427 |     const kyiv = await apiStores(`city=${encodeURIComponent('Київ')}&perPage=20`);
  428 |     const id = await createSupplier(page, {
  429 |       name: testSupplierName('пошук-київ'),
  430 |       edrpou: nextTestEdrpou(),
  431 |       contact: 'UITEST',
  432 |       phone: '+380501118811',
  433 |       email: 'uitest.kyiv@rampa.test',
  434 |     });
  435 | 
  436 |     await goto(page, `/suppliers/${id}`);
  437 |     await openTab(page, 'Магазини');
  438 |     await page.locator('#access-mode').selectOption('whitelist');
  439 | 
  440 |     const found = await multiSelectSearch(page, 'Магазини', 'Київ');
  441 |     expect(
  442 |       found.length,
  443 |       `пошук «Київ» показав ${found.length} філій, а в базі їх ${kyiv.total}`,
  444 |     ).toBeGreaterThanOrEqual(kyiv.total);
  445 |   });
  446 | 
  447 |   test('A-10.18 у виборі магазинів немає непридатних записів без міста й адреси', async ({
  448 |     page,
  449 |   }) => {
  450 |     const id = await createSupplier(page, {
  451 |       name: testSupplierName('сміття'),
  452 |       edrpou: nextTestEdrpou(),
  453 |       contact: 'UITEST',
  454 |       phone: '+380501119911',
  455 |       email: 'uitest.junk@rampa.test',
  456 |     });
  457 | 
  458 |     await goto(page, `/suppliers/${id}`);
  459 |     await openTab(page, 'Магазини');
  460 |     await page.locator('#access-mode').selectOption('whitelist');
  461 | 
  462 |     const options = await multiSelectOptions(page, 'Магазини');
  463 |     const junk = options.filter((o) => /—\s*,\s*$/.test(o.trim()) || /—\s*,$/.test(o.trim()));
  464 |     expect(
  465 |       junk,
  466 |       `у виборі є ${junk.length} записів без міста й адреси: ${junk.slice(0, 5).join(' | ')}`,
  467 |     ).toEqual([]);
  468 |   });
  469 | 
  470 |   test('A-10.19 форма створення дозволяє одразу задати доступ до магазинів', async ({ page }) => {
  471 |     await goto(page, '/suppliers/new');
  472 |     const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  473 |     expect(
  474 |       text.includes('Доступ до магазинів') || text.includes('Усі магазини'),
  475 |       'режим доступу до магазинів — частина заведення контрагента, ' +
  476 |         'інакше новий постачальник до першого редагування має доступ за замовчуванням',
> 477 |     ).toBe(true);
      |       ^ Error: режим доступу до магазинів — частина заведення контрагента, інакше новий постачальник до першого редагування має доступ за замовчуванням
  478 |   });
  479 | 
  480 |   test('A-10.20 видалення щойно створеного постачальника без бронювань', async ({ page }) => {
  481 |     const name = testSupplierName('видалення');
  482 |     const id = await createSupplier(page, {
  483 |       name,
  484 |       edrpou: nextTestEdrpou(),
  485 |       contact: 'UITEST',
  486 |       phone: '+380501110011',
  487 |       email: 'uitest.del@rampa.test',
  488 |     });
  489 | 
  490 |     await goto(page, `/suppliers/${id}`);
  491 |     await page.locator('button.btn-danger', { hasText: 'Видалити' }).click();
  492 |     const toast = await waitForToast(page).catch(() => '');
  493 | 
  494 |     const res = await apiRaw('get', `/suppliers/${id}`);
  495 |     expect(
  496 |       res.status,
  497 |       'постачальника створено щойно, бронювань у нього бути не може, ' +
  498 |         `тож видалення має спрацювати; повідомлення на екрані: «${toast}»`,
  499 |     ).toBe(404);
  500 |   });
  501 | 
  502 |   test('A-10.21 видалення постачальника з бронюваннями відхиляється', async () => {
  503 |     // Бронювання створюються лише в кабінеті постачальника (/api/supplier/v1),
  504 |     // тож із адмін-панелі підготувати умову «є активні бронювання» неможливо.
  505 |     // Видаляти демо-постачальника наосліп не можна: якщо бронювань немає,
  506 |     // перевірка знищить дані стенду. Сценарій лишається непокритим свідомо.
  507 |     test.skip(
  508 |       true,
  509 |       'потрібне бронювання, яке створюється поза адмін-панеллю (кабінет постачальника, S-06)',
  510 |     );
  511 |   });
  512 | });
  513 | 
```