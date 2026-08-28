# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 24-supplier-route-sheets.spec.ts >> S-08 Маршрутні листи >> S-08.10 вибір «Водія не призначено» у листі не показує неправду
- Location: tests/24-supplier-route-sheets.spec.ts:389:7

# Error details

```
TimeoutError: locator.selectOption: Timeout 15000ms exceeded.
Call log:
  - waiting for locator('#sheet-driver')
    - locator resolved to <select class="select" id="sheet-driver" _ngcontent-ng-c2391197793="">…</select>
  - attempting select option action
    2 × waiting for element to be visible and enabled
      - option being selected is not enabled
    - retrying select option action
    - waiting 20ms
    2 × waiting for element to be visible and enabled
      - option being selected is not enabled
    - retrying select option action
      - waiting 100ms
    30 × waiting for element to be visible and enabled
       - option being selected is not enabled
     - retrying select option action
       - waiting 500ms

```

# Test source

```ts
  308 |     expect(point!.orderId, 'номер замовлення не має загубитись').toBe(seeded.orderId);
  309 |     expect(
  310 |       sheet.points.filter((p) => p.orderId === seeded.orderId).length,
  311 |       'старої точки в листі лишатись не має',
  312 |     ).toBe(1);
  313 | 
  314 |     const fresh = await api.slots(seeded.store.storeId, date);
  315 |     const oldSlot = fresh.slots.find(
  316 |       (s) => s.slotStart === seeded.slot.slotStart && s.rampId === seeded.slot.rampId,
  317 |     );
  318 |     expect(oldSlot?.state, 'старий слот має звільнитись').toBe('available');
  319 |   });
  320 | 
  321 |   test('S-08.7 скасування з причиною звільняє слот', async ({ page }) => {
  322 |     const date = workingDay(3);
  323 |     const seeded = await seedBooking({ date, storeIndex: 1 });
  324 | 
  325 |     await loginSupplier(page);
  326 |     await goto(page, `/route-sheets/${date}`);
  327 |     const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
  328 |     await expect(myRow).toBeVisible();
  329 |     await myRow.locator('button:has-text("Скасувати")').click();
  330 | 
  331 |     await expect(page.locator('.modal__title')).toHaveText('Скасувати бронювання?');
  332 |     await page.locator('#cancel-reason').fill('UITEST: перевірка скасування');
  333 |     const [cancelled] = await Promise.all([
  334 |       page.waitForResponse((r) => r.url().includes('/bookings/') && r.request().method() === 'DELETE'),
  335 |       page.locator('.modal__window button:has-text("Скасувати")').click(),
  336 |     ]);
  337 |     expect(cancelled.status()).toBe(200);
  338 | 
  339 |     await expect(toast(page, 'Бронювання скасовано')).toBeVisible();
  340 |     await expect(page.locator('.table tbody tr').filter({ hasText: seeded.orderId })).toHaveCount(0);
  341 | 
  342 |     const fresh = await api.slots(seeded.store.storeId, date);
  343 |     const slot = fresh.slots.find((s) => s.slotStart === seeded.slot.slotStart && s.rampId === seeded.slot.rampId);
  344 |     expect(slot?.state, 'слот скасованого бронювання має звільнитись').toBe('available');
  345 |   });
  346 | 
  347 |   test('S-08.8 друкована форма містить усі потрібні поля', async ({ page }) => {
  348 |     const date = workingDay(4);
  349 |     const seeded = await seedBooking({ date });
  350 | 
  351 |     await loginSupplier(page);
  352 |     await goto(page, `/route-sheets/${date}`);
  353 |     await Promise.all([
  354 |       // Перехід усередині SPA не змінює стан завантаження сторінки,
  355 |       // тому чекаємо саме на дані листа.
  356 |       page.waitForResponse((r) => r.url().includes('/route-sheets?') && r.url().includes(date), { timeout: 20_000 }),
  357 |       page.locator('a:has-text("Роздрукувати")').click(),
  358 |     ]);
  359 |     await page.waitForURL(new RegExp(`/route-sheets/${date}/print`), { timeout: 20_000 });
  360 |     await expect(page.locator('body')).not.toContainText('Завантаження…');
  361 | 
  362 |     const text = await bodyText(page);
  363 |     const sheet = await api.sheet(date);
  364 | 
  365 |     // Заголовки таблиці набрані капітеллю через CSS — порівнюємо без регістру.
  366 |     const lower = text.toLocaleLowerCase('uk-UA');
  367 |     expect(text, 'заголовок').toContain('Маршрутний лист');
  368 |     expect(text, 'постачальник').toContain(sheet.supplierName ?? 'ТОВ');
  369 |     expect(text, 'номер версії листа').toMatch(/Версія листа № \d+/);
  370 |     expect(text, 'місце для водія').toContain('Водій');
  371 |     expect(text, 'місце для телефону').toContain('Телефон');
  372 |     expect(lower, 'підпис представника магазину').toContain('підпис представника магазину');
  373 |     for (const column of ['час', 'магазин', 'адреса', 'авто', 'замовлення', 'палети']) {
  374 |       expect(lower, `колонка «${column}»`).toContain(column);
  375 |     }
  376 | 
  377 |     for (const point of sheet.points) {
  378 |       expect(text, `час ${point.localTime}`).toContain(point.localTime);
  379 |       expect(text, `адреса ${point.address}`).toContain(point.address);
  380 |       expect(text, `авто ${point.plateNumber}`).toContain(point.plateNumber);
  381 |       expect(text, `палети ${point.palletsCount}`).toContain(String(point.palletsCount));
  382 |       if (point.orderId) {
  383 |         expect(text, `замовлення ${point.orderId}`).toContain(point.orderId);
  384 |       }
  385 |     }
  386 |     expect(text, 'моє бронювання має бути у друкованій формі').toContain(seeded.plate);
  387 |   });
  388 | 
  389 |   test('S-08.10 вибір «Водія не призначено» у листі не показує неправду', async ({ page }) => {
  390 |     const date = workingDay(5);
  391 |     const seeded = await seedBooking({ date });
  392 |     await api.assignSheetDriver(date, driver.id);
  393 | 
  394 |     await loginSupplier(page);
  395 |     await goto(page, `/route-sheets/${date}`);
  396 |     const select = page.locator('#sheet-driver');
  397 |     await expect(select).toBeVisible();
  398 |     await expect(select.locator('option:checked')).toContainText(driver.lastName);
  399 | 
  400 |     // Кабінет свідомо не вміє знімати водія з усього листа, але порожній
  401 |     // варіант у списку лишається доступним для вибору.
  402 |     let requested = false;
  403 |     page.on('request', (r) => {
  404 |       if (r.url().includes('/route-sheets/driver')) {
  405 |         requested = true;
  406 |       }
  407 |     });
> 408 |     await select.selectOption('');
      |                  ^ TimeoutError: locator.selectOption: Timeout 15000ms exceeded.
  409 |     await page.waitForTimeout(1200);
  410 | 
  411 |     const stillAssigned =
  412 |       (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId === driver.id;
  413 |     const showsNoDriver = normalizedText(await select.locator('option:checked').innerText()).includes(
  414 |       'Водія не призначено',
  415 |     );
  416 | 
  417 |     expect(
  418 |       requested || !showsNoDriver || !stillAssigned,
  419 |       'у списку показано «Водія не призначено», хоча водій листа не змінився: ' +
  420 |         'керування має або виконувати дію, або не пропонувати недоступний варіант',
  421 |     ).toBe(true);
  422 |   });
  423 | 
  424 |   test('S-08.9 X-07 маршрутні листи українською, без ключів перекладу', async ({ page }) => {
  425 |     const date = workingDay(4);
  426 |     await loginSupplier(page);
  427 |     for (const path of ['/route-sheets', `/route-sheets/${date}`, `/route-sheets/${date}/print`]) {
  428 |       await goto(page, path);
  429 |       await page.waitForTimeout(500);
  430 |       const problems = languageProblems(await bodyText(page));
  431 |       expect(problems, `${path}: ${problems.join(', ')}`).toHaveLength(0);
  432 |     }
  433 |   });
  434 | });
  435 | 
  436 | test.describe('S-11 Ліміт бронювань', () => {
  437 |   test('S-11 повідомлення про ліміт активних бронювань', async () => {
  438 |     const limit = 50;
  439 |     test.skip(
  440 |       true,
  441 |       `ліміт активних майбутніх бронювань постачальника — ${limit} (StorePolicy.maxActiveBookingsPerSupplier). ` +
  442 |         'Щоб побачити повідомлення в інтерфейсі, треба створити майже 50 бронювань на спільному стенді — ' +
  443 |         'це заблокувало б слоти для решти перевірок і для демо. Перевірку слід виконувати на окремому ' +
  444 |         'стенді або після зниження ліміту в конфігурації магазину адміністратором.',
  445 |     );
  446 |   });
  447 | });
  448 | 
```