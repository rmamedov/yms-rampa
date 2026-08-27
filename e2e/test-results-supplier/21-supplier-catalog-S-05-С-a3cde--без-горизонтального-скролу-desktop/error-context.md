# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 21-supplier-catalog.spec.ts >> S-05 Сітка слотів >> S-05.9 X-10 адаптивність 360/768/1280 без горизонтального скролу
- Location: tests/21-supplier-catalog.spec.ts:465:7

# Error details

```
Error: 360px /route-sheets/2026-08-28/print: зайві 265px по горизонталі

expect(received).toHaveLength(expected)

Expected length: 0
Received length: 1
Received array:  ["360px /route-sheets/2026-08-28/print: зайві 265px по горизонталі"]
```

# Test source

```ts
  400 |       expect(await page.locator('.panel').count(), 'панель бронювання не має відкритись').toBe(0);
  401 |     } finally {
  402 |       await api.cancelBooking(booking.id);
  403 |     }
  404 |   });
  405 | 
  406 |   test('S-05.10 підписи рамп не дублюють слово «Рампа»', async ({ page }) => {
  407 |     const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
  408 |     await loginSupplier(page);
  409 |     await goto(page, `/booking/stores/${store.storeId}`);
  410 |     await expect(page.locator('.slot-grid')).toBeVisible();
  411 | 
  412 |     const headers = (await page.locator('.slot-grid thead th').allInnerTexts()).map(normalizedText);
  413 |     const doubled = headers.filter((h) => /рампа\s+рампа/i.test(h));
  414 |     expect(doubled, `заголовки колонок: ${headers.join(' | ')}`).toHaveLength(0);
  415 | 
  416 |     const aria = await page.locator('.slot').first().getAttribute('aria-label');
  417 |     expect(aria ?? '', 'підпис для читача екрана також не має дублювати слово').not.toMatch(/рампа\s+рампа/i);
  418 |   });
  419 | 
  420 |   test('S-05.6 X-08 під час завантаження сітки видно індикатор', async ({ page }) => {
  421 |     const store = kharkiv[0];
  422 |     await loginSupplier(page);
  423 | 
  424 |     await page.route('**/slots?*', async (route) => {
  425 |       await new Promise((resolve) => setTimeout(resolve, 2500));
  426 |       await route.continue();
  427 |     });
  428 | 
  429 |     await page.goto(HOSTS.supplier + `/booking/stores/${store.storeId}`);
  430 |     await expect(page.locator('.spinner').first(), 'має зʼявитись індикатор завантаження').toBeVisible({
  431 |       timeout: 10_000,
  432 |     });
  433 |     await expect(page.locator('body')).toContainText('Завантаження…');
  434 |     await page.unroute('**/slots?*');
  435 |   });
  436 | 
  437 |   test('S-05.7 X-06 недоступна філія повідомляє про це текстом', async ({ page }) => {
  438 |     await loginSupplier(page);
  439 |     await goto(page, '/booking/stores/00000000-0000-4000-8000-000000000000');
  440 |     await page.waitForTimeout(1500);
  441 | 
  442 |     const text = await bodyText(page);
  443 |     expect(
  444 |       /недоступна вашому підприємству|не знайдено|помилка/i.test(text),
  445 |       `екран має пояснити проблему, а не мовчати. Текст: ${text.slice(0, 300)}`,
  446 |     ).toBe(true);
  447 |   });
  448 | 
  449 |   test('S-05.8 X-07 екрани вибору без ключів перекладу і англійських слів', async ({ page }) => {
  450 |     await loginSupplier(page);
  451 |     const store = kharkiv[0];
  452 | 
  453 |     for (const path of [
  454 |       '/booking/cities',
  455 |       `/booking/cities/${encodeURIComponent('Харків')}`,
  456 |       `/booking/stores/${store.storeId}`,
  457 |     ]) {
  458 |       await goto(page, path);
  459 |       await page.waitForTimeout(500);
  460 |       const problems = languageProblems(await bodyText(page));
  461 |       expect(problems, `${path}: неперекладені фрагменти ${problems.join(', ')}`).toHaveLength(0);
  462 |     }
  463 |   });
  464 | 
  465 |   test('S-05.9 X-10 адаптивність 360/768/1280 без горизонтального скролу', async ({ page }) => {
  466 |     await loginSupplier(page);
  467 |     const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
  468 |     const screens = [
  469 |       '/home',
  470 |       '/booking/cities',
  471 |       `/booking/cities/${encodeURIComponent('Київ')}`,
  472 |       `/booking/stores/${store.storeId}`,
  473 |       '/route-sheets',
  474 |       // Найширші таблиці кабінету — саме тут найімовірніший горизонтальний скрол.
  475 |       `/route-sheets/${kyivToday()}`,
  476 |       `/route-sheets/${kyivToday()}/print`,
  477 |       '/vehicles',
  478 |       '/drivers',
  479 |     ];
  480 |     const failures: string[] = [];
  481 | 
  482 |     for (const size of [
  483 |       { width: 360, height: 780 },
  484 |       { width: 768, height: 1024 },
  485 |       { width: 1280, height: 900 },
  486 |     ]) {
  487 |       await page.setViewportSize(size);
  488 |       for (const path of screens) {
  489 |         await goto(page, path);
  490 |         await page.waitForTimeout(400);
  491 |         const overflow = await page.evaluate(
  492 |           () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  493 |         );
  494 |         if (overflow > 1) {
  495 |           failures.push(`${size.width}px ${path}: зайві ${overflow}px по горизонталі`);
  496 |         }
  497 |       }
  498 |     }
  499 | 
> 500 |     expect(failures, failures.join('; ')).toHaveLength(0);
      |                                           ^ Error: 360px /route-sheets/2026-08-28/print: зайві 265px по горизонталі
  501 |   });
  502 | });
  503 | 
```