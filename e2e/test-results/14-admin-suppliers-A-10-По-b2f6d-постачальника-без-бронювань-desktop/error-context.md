# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 14-admin-suppliers.spec.ts >> A-10 Постачальники >> A-10.20 видалення щойно створеного постачальника без бронювань
- Location: tests/14-admin-suppliers.spec.ts:480:7

# Error details

```
Error: постачальника створено щойно, бронювань у нього бути не може, тож видалення має спрацювати; повідомлення на екрані: «Постачальника видалено»

expect(received).toBe(expected) // Object.is equality

Expected: 404
Received: 200
```

# Page snapshot

```yaml
- generic [ref=f2e2]:
  - generic [ref=f2e4]:
    - complementary [ref=f2e5]:
      - generic [ref=f2e6]: YMS «Рампа»
      - navigation [ref=f2e7]:
        - link "Магазини" [ref=f2e8] [cursor=pointer]:
          - /url: /stores
        - link "Постачальники" [ref=f2e9] [cursor=pointer]:
          - /url: /suppliers
        - link "Користувачі" [ref=f2e10] [cursor=pointer]:
          - /url: /users
        - link "Синхронізація MCP" [ref=f2e11] [cursor=pointer]:
          - /url: /mcp-sync
        - link "Аналітика" [ref=f2e12] [cursor=pointer]:
          - /url: /analytics
      - generic [ref=f2e13]:
        - generic [ref=f2e14]: Адміністратор мережі
        - generic [ref=f2e15]: Супер-адміністратор
        - button "Вийти" [ref=f2e16] [cursor=pointer]
    - main [ref=f2e17]:
      - generic [ref=f2e18]:
        - generic [ref=f2e19]:
          - heading "Постачальники" [level=1] [ref=f2e20]
          - button "Додати постачальника" [ref=f2e21] [cursor=pointer]
        - generic [ref=f2e22]:
          - generic [ref=f2e23]:
            - generic [ref=f2e24]: Пошук
            - searchbox "Пошук" [ref=f2e25]
          - generic [ref=f2e26]:
            - generic [ref=f2e27]: Статус
            - combobox "Статус" [ref=f2e28]:
              - option "Усі" [selected]
              - option "Активний"
              - option "Призупинений"
          - button "Застосувати" [ref=f2e29] [cursor=pointer]
          - button "Скинути фільтри" [ref=f2e30] [cursor=pointer]
        - generic [ref=f2e31]:
          - table [ref=f2e32]:
            - rowgroup [ref=f2e33]:
              - row [ref=f2e34]:
                - columnheader [ref=f2e35]:
                  - checkbox "select-all-suppliers" [ref=f2e36]
                - columnheader "Назва" [ref=f2e37]
                - columnheader "ЄДРПОУ" [ref=f2e38]
                - columnheader "Контактна особа" [ref=f2e39]
                - columnheader "Телефон" [ref=f2e40]
                - columnheader "Статус" [ref=f2e41]
                - columnheader "Доступ до магазинів" [ref=f2e42]
            - rowgroup [ref=f2e43]:
              - row [ref=f2e44]:
                - cell [ref=f2e45]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-доступ" [ref=f2e46]
                - cell [ref=f2e47]:
                  - link "UITEST-Постачальник-mtc2alvh45-доступ" [ref=f2e48] [cursor=pointer]:
                    - /url: /suppliers/e6baa877-fe9e-48da-a016-af21c746c755
                - cell "99002872" [ref=f2e49]
                - cell "UITEST" [ref=f2e50]
                - cell "+380501116611" [ref=f2e51]
                - cell "Активний" [ref=f2e52]
                - cell "Усі магазини" [ref=f2e54]
              - row [ref=f2e55]:
                - cell [ref=f2e56]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-дублікат-1" [ref=f2e57]
                - cell [ref=f2e58]:
                  - link "UITEST-Постачальник-mtc2alvh45-дублікат-1" [ref=f2e59] [cursor=pointer]:
                    - /url: /suppliers/3b8f30f5-f19e-4b97-ab8a-e8b83cb14904
                - cell "99002866" [ref=f2e60]
                - cell "UITEST" [ref=f2e61]
                - cell "+380501112244" [ref=f2e62]
                - cell "Активний" [ref=f2e63]
                - cell "Усі магазини" [ref=f2e65]
              - row [ref=f2e66]:
                - cell [ref=f2e67]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-назва-дубль" [ref=f2e68]
                - cell [ref=f2e69]:
                  - link "UITEST-Постачальник-mtc2alvh45-назва-дубль" [ref=f2e70] [cursor=pointer]:
                    - /url: /suppliers/7e893821-7473-4f15-b1a3-dd2b7a27c245
                - cell "99002867" [ref=f2e71]
                - cell "UITEST" [ref=f2e72]
                - cell "+380501112266" [ref=f2e73]
                - cell "Активний" [ref=f2e74]
                - cell "Усі магазини" [ref=f2e76]
              - row [ref=f2e77]:
                - cell [ref=f2e78]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-пауза" [ref=f2e79]
                - cell [ref=f2e80]:
                  - link "UITEST-Постачальник-mtc2alvh45-пауза" [ref=f2e81] [cursor=pointer]:
                    - /url: /suppliers/7b83b8a0-e502-4230-82e2-a24b43e1c095
                - cell "99002870" [ref=f2e82]
                - cell "UITEST" [ref=f2e83]
                - cell "+380501114411" [ref=f2e84]
                - cell "Активний" [ref=f2e85]
                - cell "Усі магазини" [ref=f2e87]
              - row [ref=f2e88]:
                - cell [ref=f2e89]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-повнота" [ref=f2e90]
                - cell [ref=f2e91]:
                  - link "UITEST-Постачальник-mtc2alvh45-повнота" [ref=f2e92] [cursor=pointer]:
                    - /url: /suppliers/6eefec30-fabc-49d9-bce4-2d66c0a30bb9
                - cell "99002873" [ref=f2e93]
                - cell "UITEST" [ref=f2e94]
                - cell "+380501117711" [ref=f2e95]
                - cell "Активний" [ref=f2e96]
                - cell "Усі магазини" [ref=f2e98]
              - row [ref=f2e99]:
                - cell [ref=f2e100]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-редагування" [ref=f2e101]
                - cell [ref=f2e102]:
                  - link "UITEST-Постачальник-mtc2alvh45-редагування" [ref=f2e103] [cursor=pointer]:
                    - /url: /suppliers/95271455-6293-4c71-8291-150e4b8e3e9a
                - cell "99002869" [ref=f2e104]
                - cell "UITEST Після" [ref=f2e105]
                - cell "+380501113322" [ref=f2e106]
                - cell "Активний" [ref=f2e107]
                - cell "Усі магазини" [ref=f2e109]
              - row [ref=f2e110]:
                - cell [ref=f2e111]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-створення" [ref=f2e112]
                - cell [ref=f2e113]:
                  - link "UITEST-Постачальник-mtc2alvh45-створення" [ref=f2e114] [cursor=pointer]:
                    - /url: /suppliers/2e0ca956-b834-47d2-b407-4f6e6a4bb165
                - cell "99002865" [ref=f2e115]
                - cell "UITEST Контактна Особа" [ref=f2e116]
                - cell "+380501112233" [ref=f2e117]
                - cell "Активний" [ref=f2e118]
                - cell "Усі магазини" [ref=f2e120]
              - row [ref=f2e121]:
                - cell [ref=f2e122]:
                  - checkbox "UITEST-Постачальник-mtc2alvh45-фільтр" [ref=f2e123]
                - cell [ref=f2e124]:
                  - link "UITEST-Постачальник-mtc2alvh45-фільтр" [ref=f2e125] [cursor=pointer]:
                    - /url: /suppliers/fcf6b65c-6a5b-4db5-b477-3b9c02ee09a8
                - cell "99002871" [ref=f2e126]
                - cell "UITEST" [ref=f2e127]
                - cell "+380501115511" [ref=f2e128]
                - cell "Призупинений" [ref=f2e129]
                - cell "Усі магазини" [ref=f2e131]
              - row [ref=f2e132]:
                - cell [ref=f2e133]:
                  - checkbox "UITEST-Постачальник-mtc2c2li247-пошук-київ" [ref=f2e134]
                - cell [ref=f2e135]:
                  - link "UITEST-Постачальник-mtc2c2li247-пошук-київ" [ref=f2e136] [cursor=pointer]:
                    - /url: /suppliers/4dbac76e-2008-4363-a2db-ba3802e61cc6
                - cell "99008701" [ref=f2e137]
                - cell "UITEST" [ref=f2e138]
                - cell "+380501118811" [ref=f2e139]
                - cell "Активний" [ref=f2e140]
                - cell "Усі магазини" [ref=f2e142]
              - row [ref=f2e143]:
                - cell [ref=f2e144]:
                  - checkbox "UITEST-Постачальник-mtc2c2li247-сміття" [ref=f2e145]
                - cell [ref=f2e146]:
                  - link "UITEST-Постачальник-mtc2c2li247-сміття" [ref=f2e147] [cursor=pointer]:
                    - /url: /suppliers/3a4967c8-f946-4a31-a702-f943b3f6c716
                - cell "99008702" [ref=f2e148]
                - cell "UITEST" [ref=f2e149]
                - cell "+380501119911" [ref=f2e150]
                - cell "Активний" [ref=f2e151]
                - cell "Усі магазини" [ref=f2e153]
              - row [ref=f2e154]:
                - cell [ref=f2e155]:
                  - checkbox "UITEST-Постачальник-mtc2ccxa508-видалення" [ref=f2e156]
                - cell [ref=f2e157]:
                  - link "UITEST-Постачальник-mtc2ccxa508-видалення" [ref=f2e158] [cursor=pointer]:
                    - /url: /suppliers/2a69eb6b-93e8-431a-8027-c5f060574c5e
                - cell "99006202" [ref=f2e159]
                - cell "UITEST" [ref=f2e160]
                - cell "+380501110011" [ref=f2e161]
                - cell "Активний" [ref=f2e162]
                - cell "Усі магазини" [ref=f2e164]
              - row [ref=f2e165]:
                - cell [ref=f2e166]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-доступ" [ref=f2e167]
                - cell [ref=f2e168]:
                  - link "UITEST-Постачальник-mtc3355n104-доступ" [ref=f2e169] [cursor=pointer]:
                    - /url: /suppliers/6d3d95bb-e1ba-4104-8fb4-4b8e1e7b18e3
                - cell "99001674" [ref=f2e170]
                - cell "UITEST" [ref=f2e171]
                - cell "+380501116611" [ref=f2e172]
                - cell "Активний" [ref=f2e173]
                - cell "Усі магазини" [ref=f2e175]
              - row [ref=f2e176]:
                - cell [ref=f2e177]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-дублікат-1" [ref=f2e178]
                - cell [ref=f2e179]:
                  - link "UITEST-Постачальник-mtc3355n104-дублікат-1" [ref=f2e180] [cursor=pointer]:
                    - /url: /suppliers/120c1737-1962-497e-bcc4-93a8b3d9de55
                - cell "99001668" [ref=f2e181]
                - cell "UITEST" [ref=f2e182]
                - cell "+380501112244" [ref=f2e183]
                - cell "Активний" [ref=f2e184]
                - cell "Усі магазини" [ref=f2e186]
              - row [ref=f2e187]:
                - cell [ref=f2e188]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-назва-дубль" [ref=f2e189]
                - cell [ref=f2e190]:
                  - link "UITEST-Постачальник-mtc3355n104-назва-дубль" [ref=f2e191] [cursor=pointer]:
                    - /url: /suppliers/223cf7d2-2032-4d45-a2a5-916fa703b7ca
                - cell "99001669" [ref=f2e192]
                - cell "UITEST" [ref=f2e193]
                - cell "+380501112266" [ref=f2e194]
                - cell "Активний" [ref=f2e195]
                - cell "Усі магазини" [ref=f2e197]
              - row [ref=f2e198]:
                - cell [ref=f2e199]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-пауза" [ref=f2e200]
                - cell [ref=f2e201]:
                  - link "UITEST-Постачальник-mtc3355n104-пауза" [ref=f2e202] [cursor=pointer]:
                    - /url: /suppliers/e0a1b6b1-77cc-4a87-9a40-d2361dc59e9d
                - cell "99001672" [ref=f2e203]
                - cell "UITEST" [ref=f2e204]
                - cell "+380501114411" [ref=f2e205]
                - cell "Активний" [ref=f2e206]
                - cell "Усі магазини" [ref=f2e208]
              - row [ref=f2e209]:
                - cell [ref=f2e210]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-пошук-київ" [ref=f2e211]
                - cell [ref=f2e212]:
                  - link "UITEST-Постачальник-mtc3355n104-пошук-київ" [ref=f2e213] [cursor=pointer]:
                    - /url: /suppliers/1422e2be-2b05-4af7-8849-e595228bc289
                - cell "99001675" [ref=f2e214]
                - cell "UITEST" [ref=f2e215]
                - cell "+380501118811" [ref=f2e216]
                - cell "Активний" [ref=f2e217]
                - cell "Усі магазини" [ref=f2e219]
              - row [ref=f2e220]:
                - cell [ref=f2e221]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-редагування" [ref=f2e222]
                - cell [ref=f2e223]:
                  - link "UITEST-Постачальник-mtc3355n104-редагування" [ref=f2e224] [cursor=pointer]:
                    - /url: /suppliers/d6734559-a2e9-4904-97b8-2b0171d7c40d
                - cell "99001671" [ref=f2e225]
                - cell "UITEST Після" [ref=f2e226]
                - cell "+380501113322" [ref=f2e227]
                - cell "Активний" [ref=f2e228]
                - cell "Усі магазини" [ref=f2e230]
              - row [ref=f2e231]:
                - cell [ref=f2e232]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-сміття" [ref=f2e233]
                - cell [ref=f2e234]:
                  - link "UITEST-Постачальник-mtc3355n104-сміття" [ref=f2e235] [cursor=pointer]:
                    - /url: /suppliers/f55bf596-6597-4456-b578-cef0946088d9
                - cell "99001676" [ref=f2e236]
                - cell "UITEST" [ref=f2e237]
                - cell "+380501119911" [ref=f2e238]
                - cell "Активний" [ref=f2e239]
                - cell "Усі магазини" [ref=f2e241]
              - row [ref=f2e242]:
                - cell [ref=f2e243]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-створення" [ref=f2e244]
                - cell [ref=f2e245]:
                  - link "UITEST-Постачальник-mtc3355n104-створення" [ref=f2e246] [cursor=pointer]:
                    - /url: /suppliers/dee87cf7-7380-4607-aaca-5fada2007c5a
                - cell "99001667" [ref=f2e247]
                - cell "UITEST Контактна Особа" [ref=f2e248]
                - cell "+380501112233" [ref=f2e249]
                - cell "Активний" [ref=f2e250]
                - cell "Усі магазини" [ref=f2e252]
              - row [ref=f2e253]:
                - cell [ref=f2e254]:
                  - checkbox "UITEST-Постачальник-mtc3355n104-фільтр" [ref=f2e255]
                - cell [ref=f2e256]:
                  - link "UITEST-Постачальник-mtc3355n104-фільтр" [ref=f2e257] [cursor=pointer]:
                    - /url: /suppliers/2de85ff3-2381-4391-a2f4-190220160fc9
                - cell "99001673" [ref=f2e258]
                - cell "UITEST" [ref=f2e259]
                - cell "+380501115511" [ref=f2e260]
                - cell "Призупинений" [ref=f2e261]
                - cell "Усі магазини" [ref=f2e263]
          - generic [ref=f2e265]:
            - generic [ref=f2e266]: "Усього: 44"
            - generic [ref=f2e267]:
              - text: Рядків на сторінці
              - combobox "page-size" [ref=f2e268]:
                - option "20" [selected]
                - option "50"
                - option "100"
            - button "‹" [disabled] [ref=f2e269]
            - generic [ref=f2e270]: Сторінка 1 з 3
            - button "›" [ref=f2e271] [cursor=pointer]
  - generic [ref=f2e273]:
    - generic [ref=f2e274]: Постачальника видалено
    - button "✕" [ref=f2e275] [cursor=pointer]
```

# Test source

```ts
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
  477 |     ).toBe(true);
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
> 499 |     ).toBe(404);
      |       ^ Error: постачальника створено щойно, бронювань у нього бути не може, тож видалення має спрацювати; повідомлення на екрані: «Постачальника видалено»
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