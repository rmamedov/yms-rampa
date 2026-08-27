# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 24-supplier-route-sheets.spec.ts >> S-08 Маршрутні листи >> S-08.10 вибір «Водія не призначено» у листі не показує неправду
- Location: tests/24-supplier-route-sheets.spec.ts:392:7

# Error details

```
Error: у списку показано «Водія не призначено», хоча водій листа не змінився: керування має або виконувати дію, або не пропонувати недоступний варіант

expect(received).toBe(expected) // Object.is equality

Expected: true
Received: false
```

# Test source

```ts
  324 |   test('S-08.7 скасування з причиною звільняє слот', async ({ page }) => {
  325 |     const date = workingDay(3);
  326 |     const seeded = await seedBooking({ date, storeIndex: 1 });
  327 | 
  328 |     await loginSupplier(page);
  329 |     await goto(page, `/route-sheets/${date}`);
  330 |     const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
  331 |     await expect(myRow).toBeVisible();
  332 |     await myRow.locator('button:has-text("Скасувати")').click();
  333 | 
  334 |     await expect(page.locator('.modal__title')).toHaveText('Скасувати бронювання?');
  335 |     await page.locator('#cancel-reason').fill('UITEST: перевірка скасування');
  336 |     const [cancelled] = await Promise.all([
  337 |       page.waitForResponse((r) => r.url().includes('/bookings/') && r.request().method() === 'DELETE'),
  338 |       page.locator('.modal__window button:has-text("Скасувати")').click(),
  339 |     ]);
  340 |     expect(cancelled.status()).toBe(200);
  341 | 
  342 |     await expect(toast(page, 'Бронювання скасовано')).toBeVisible();
  343 |     await expect(page.locator('.table tbody tr').filter({ hasText: seeded.orderId })).toHaveCount(0);
  344 | 
  345 |     const fresh = await api.slots(seeded.store.storeId, date);
  346 |     const slot = fresh.slots.find((s) => s.slotStart === seeded.slot.slotStart && s.rampId === seeded.slot.rampId);
  347 |     expect(slot?.state, 'слот скасованого бронювання має звільнитись').toBe('available');
  348 |   });
  349 | 
  350 |   test('S-08.8 друкована форма містить усі потрібні поля', async ({ page }) => {
  351 |     const date = workingDay(4);
  352 |     const seeded = await seedBooking({ date });
  353 | 
  354 |     await loginSupplier(page);
  355 |     await goto(page, `/route-sheets/${date}`);
  356 |     await Promise.all([
  357 |       // Перехід усередині SPA не змінює стан завантаження сторінки,
  358 |       // тому чекаємо саме на дані листа.
  359 |       page.waitForResponse((r) => r.url().includes('/route-sheets?') && r.url().includes(date), { timeout: 20_000 }),
  360 |       page.locator('a:has-text("Роздрукувати")').click(),
  361 |     ]);
  362 |     await page.waitForURL(new RegExp(`/route-sheets/${date}/print`), { timeout: 20_000 });
  363 |     await expect(page.locator('body')).not.toContainText('Завантаження…');
  364 | 
  365 |     const text = await bodyText(page);
  366 |     const sheet = await api.sheet(date);
  367 | 
  368 |     // Заголовки таблиці набрані капітеллю через CSS — порівнюємо без регістру.
  369 |     const lower = text.toLocaleLowerCase('uk-UA');
  370 |     expect(text, 'заголовок').toContain('Маршрутний лист');
  371 |     expect(text, 'постачальник').toContain(sheet.supplierName ?? 'ТОВ');
  372 |     expect(text, 'номер версії листа').toMatch(/Версія листа № \d+/);
  373 |     expect(text, 'місце для водія').toContain('Водій');
  374 |     expect(text, 'місце для телефону').toContain('Телефон');
  375 |     expect(lower, 'підпис представника магазину').toContain('підпис представника магазину');
  376 |     for (const column of ['час', 'магазин', 'адреса', 'авто', 'замовлення', 'палети']) {
  377 |       expect(lower, `колонка «${column}»`).toContain(column);
  378 |     }
  379 | 
  380 |     for (const point of sheet.points) {
  381 |       expect(text, `час ${point.localTime}`).toContain(point.localTime);
  382 |       expect(text, `адреса ${point.address}`).toContain(point.address);
  383 |       expect(text, `авто ${point.plateNumber}`).toContain(point.plateNumber);
  384 |       expect(text, `палети ${point.palletsCount}`).toContain(String(point.palletsCount));
  385 |       if (point.orderId) {
  386 |         expect(text, `замовлення ${point.orderId}`).toContain(point.orderId);
  387 |       }
  388 |     }
  389 |     expect(text, 'моє бронювання має бути у друкованій формі').toContain(seeded.plate);
  390 |   });
  391 | 
  392 |   test('S-08.10 вибір «Водія не призначено» у листі не показує неправду', async ({ page }) => {
  393 |     const date = workingDay(5);
  394 |     const seeded = await seedBooking({ date });
  395 |     await api.assignSheetDriver(date, driver.id);
  396 | 
  397 |     await loginSupplier(page);
  398 |     await goto(page, `/route-sheets/${date}`);
  399 |     const select = page.locator('#sheet-driver');
  400 |     await expect(select).toBeVisible();
  401 |     await expect(select.locator('option:checked')).toContainText(driver.lastName);
  402 | 
  403 |     // Кабінет свідомо не вміє знімати водія з усього листа, але порожній
  404 |     // варіант у списку лишається доступним для вибору.
  405 |     let requested = false;
  406 |     page.on('request', (r) => {
  407 |       if (r.url().includes('/route-sheets/driver')) {
  408 |         requested = true;
  409 |       }
  410 |     });
  411 |     await select.selectOption('');
  412 |     await page.waitForTimeout(1200);
  413 | 
  414 |     const stillAssigned =
  415 |       (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId === driver.id;
  416 |     const showsNoDriver = normalizedText(await select.locator('option:checked').innerText()).includes(
  417 |       'Водія не призначено',
  418 |     );
  419 | 
  420 |     expect(
  421 |       requested || !showsNoDriver || !stillAssigned,
  422 |       'у списку показано «Водія не призначено», хоча водій листа не змінився: ' +
  423 |         'керування має або виконувати дію, або не пропонувати недоступний варіант',
> 424 |     ).toBe(true);
      |       ^ Error: у списку показано «Водія не призначено», хоча водій листа не змінився: керування має або виконувати дію, або не пропонувати недоступний варіант
  425 |   });
  426 | 
  427 |   test('S-08.9 X-07 маршрутні листи українською, без ключів перекладу', async ({ page }) => {
  428 |     const date = workingDay(4);
  429 |     await loginSupplier(page);
  430 |     for (const path of ['/route-sheets', `/route-sheets/${date}`, `/route-sheets/${date}/print`]) {
  431 |       await goto(page, path);
  432 |       await page.waitForTimeout(500);
  433 |       const problems = languageProblems(await bodyText(page));
  434 |       expect(problems, `${path}: ${problems.join(', ')}`).toHaveLength(0);
  435 |     }
  436 |   });
  437 | });
  438 | 
  439 | test.describe('S-11 Ліміт бронювань', () => {
  440 |   test('S-11 повідомлення про ліміт активних бронювань', async () => {
  441 |     const limit = 50;
  442 |     test.skip(
  443 |       true,
  444 |       `ліміт активних майбутніх бронювань постачальника — ${limit} (StorePolicy.maxActiveBookingsPerSupplier). ` +
  445 |         'Щоб побачити повідомлення в інтерфейсі, треба створити майже 50 бронювань на спільному стенді — ' +
  446 |         'це заблокувало б слоти для решти перевірок і для демо. Перевірку слід виконувати на окремому ' +
  447 |         'стенді або після зниження ліміту в конфігурації магазину адміністратором.',
  448 |     );
  449 |   });
  450 | });
  451 | 
```