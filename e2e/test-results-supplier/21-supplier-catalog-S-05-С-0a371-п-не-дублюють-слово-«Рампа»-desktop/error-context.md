# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 21-supplier-catalog.spec.ts >> S-05 Сітка слотів >> S-05.10 підписи рамп не дублюють слово «Рампа»
- Location: tests/21-supplier-catalog.spec.ts:406:7

# Error details

```
Error: заголовки колонок: ЧАС | РАМПА РАМПА 1 | РАМПА РАМПА 2

expect(received).toHaveLength(expected)

Expected length: 0
Received length: 2
Received array:  ["РАМПА РАМПА 1", "РАМПА РАМПА 2"]
```

# Test source

```ts
  314 | 
  315 |     const header = normalizedText(await page.locator('.page__head').innerText());
  316 |     expect(header, 'у шапці має бути горизонт бронювання').toContain(`Горизонт бронювання — ${store.bookingHorizonDays}`);
  317 | 
  318 |     const next = page.locator('.dates > button').last();
  319 |     let guard = 0;
  320 |     while (!(await next.isDisabled()) && guard < 10) {
  321 |       await next.click();
  322 |       await page.waitForTimeout(700);
  323 |       guard++;
  324 |     }
  325 |     expect(guard, 'кнопка «Наступні дні» має врешті вимкнутись').toBeLessThan(10);
  326 | 
  327 |     const lastLabels = await dates.allInnerTexts();
  328 |     const lastDay = normalizedText(lastLabels[lastLabels.length - 1]);
  329 |     const horizonDate = shiftDate(today, store.bookingHorizonDays);
  330 |     const horizonLabel = new Intl.DateTimeFormat('uk-UA', {
  331 |       day: 'numeric',
  332 |       month: 'long',
  333 |       timeZone: 'Europe/Kyiv',
  334 |     }).format(new Date(`${horizonDate}T12:00:00Z`));
  335 |     expect(lastDay, `далі за горизонт (${store.bookingHorizonDays} дн.) стрічка йти не має`).toContain(horizonLabel);
  336 | 
  337 |     // Вибір філії не загубився при перемиканні дат.
  338 |     await expect(page).toHaveURL(new RegExp(store.storeId));
  339 |     expect(normalizedText(await page.locator('.page__head h1').innerText())).toBe(store.address);
  340 |   });
  341 | 
  342 |   test('S-05.3 X-04 день без прийому показує зрозуміле повідомлення', async ({ page }) => {
  343 |     const store = kharkiv[0];
  344 |     const sunday = nearestSunday(1);
  345 |     test.skip(diffDays(kyivToday(), sunday) > 6, 'найближча неділя поза видимою стрічкою');
  346 | 
  347 |     const grid = await api.slots(store.storeId, sunday);
  348 |     test.skip(grid.slots.length > 0, 'на цю неділю філія працює — перевірка не застосовна');
  349 | 
  350 |     await loginSupplier(page);
  351 |     await goto(page, `/booking/stores/${store.storeId}`);
  352 |     await selectGridDate(page, sunday);
  353 | 
  354 |     await expect(page.locator('.empty-state')).toHaveText('На цю дату слотів немає');
  355 |   });
  356 | 
  357 |   test('S-05.4 легенда описує всі стани слота', async ({ page }) => {
  358 |     const store = kharkiv[0];
  359 |     await loginSupplier(page);
  360 |     await goto(page, `/booking/stores/${store.storeId}`);
  361 | 
  362 |     const legend = normalizedText(await page.locator('.legend').innerText());
  363 |     for (const label of ['Вільно', 'Ваш резерв', 'Оформлюється', 'Зайнято', 'Недоступно', 'Заблоковано', 'Минув']) {
  364 |       expect(legend, `легенда має пояснювати стан «${label}»`).toContain(label);
  365 |     }
  366 |   });
  367 | 
  368 |   test('S-05.5 зайнятий слот показаний і неклікабельний', async ({ page }) => {
  369 |     const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
  370 |     const date = workingDay(2);
  371 |     const grid = await api.slots(store.storeId, date);
  372 |     const free = grid.slots.find((s) => s.state === 'available');
  373 |     expect(free, 'потрібен вільний слот для перевірки').toBeTruthy();
  374 | 
  375 |     const booking = await api.createBooking({
  376 |       storeId: store.storeId,
  377 |       rampId: free!.rampId,
  378 |       slotStart: free!.slotStart,
  379 |       plateNumber: 'UT7777XX',
  380 |       weightTons: 3,
  381 |       palletsCount: 5,
  382 |       orderId: 'UITEST-busy',
  383 |     });
  384 | 
  385 |     try {
  386 |       await loginSupplier(page);
  387 |       await goto(page, `/booking/stores/${store.storeId}`);
  388 |       await selectGridDate(page, date);
  389 | 
  390 |       const column = store.ramps.findIndex((r) => r.rampId === free!.rampId);
  391 |       const cell = cellAt(page, `${free!.localStart}`, column);
  392 | 
  393 |       await expect(cell.locator('.slot'), 'зайнятий слот має бути підписаний').toHaveText('Зайнято');
  394 |       expect(await cell.locator('button.slot').count(), 'зайнятий слот не має бути кнопкою').toBe(0);
  395 |       await expect(cell.locator('span.slot')).toHaveAttribute('aria-disabled', 'true');
  396 | 
  397 |       // Клік по недоступному слоту не має нічого відкривати.
  398 |       await cell.click({ force: true });
  399 |       await page.waitForTimeout(700);
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
> 414 |     expect(doubled, `заголовки колонок: ${headers.join(' | ')}`).toHaveLength(0);
      |                                                                  ^ Error: заголовки колонок: ЧАС | РАМПА РАМПА 1 | РАМПА РАМПА 2
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
  500 |     expect(failures, failures.join('; ')).toHaveLength(0);
  501 |   });
  502 | });
  503 | 
```