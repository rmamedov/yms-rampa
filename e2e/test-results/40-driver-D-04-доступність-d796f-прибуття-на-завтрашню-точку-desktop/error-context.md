# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 40-driver.spec.ts >> D-04 доступність відмітки за часом >> D-04 бекенд теж не приймає прибуття на завтрашню точку
- Location: tests/40-driver.spec.ts:495:7

# Error details

```
Error: бронювання на 2026-08-29 13:30: відмітка прибуття за добу наперед має бути відхилена, отримано 200

expect(received).not.toBe(expected) // Object.is equality

Expected: not 200
```

# Test source

```ts
  412 |     expect(stored?.orderId, 'номер має зберегтися в маршрутному листі').toBe(value);
  413 |   });
  414 | 
  415 |   test('D-04 «На місці» переводить точку у статус «На місці»', async ({ page }) => {
  416 |     await openRouteSheet(page, shared.driver);
  417 |     const card = pointCard(page, booking.bookingId);
  418 |     await card.scrollIntoViewIfNeeded();
  419 | 
  420 |     const arrive = card.locator('button.btn.arrive');
  421 |     await expect(arrive, 'для точки у статусі «Очікує виїзду» кнопка має бути').toBeVisible();
  422 | 
  423 |     const [response] = await Promise.all([
  424 |       page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}/arrived`)),
  425 |       arrive.click(),
  426 |     ]);
  427 |     expect(response.status()).toBe(200);
  428 | 
  429 |     // Підтвердження водієві: статус на картці змінився.
  430 |     await expect(card.locator('.badge')).toHaveText('На місці');
  431 |     await expect(card.locator('button.btn.arrive'), 'повторно натиснути вже нічим').toHaveCount(0);
  432 |   });
  433 | 
  434 |   test('D-04 повторна відмітка не ламає стан', async () => {
  435 |     const res = await shared.ctx.post(`${HOSTS.driver}/api/driver/v1/bookings/${booking.bookingId}/arrived`, {
  436 |       headers: { Authorization: `Bearer ${driverToken}` },
  437 |       data: {},
  438 |     });
  439 |     expect(res.status(), 'дія ідемпотентна: повтор повертає поточний стан').toBe(200);
  440 |     expect((await res.json()).status).toBe('arrived');
  441 |   });
  442 | 
  443 |   test('D-04 результат відмітки видно в контурі магазину', async () => {
  444 |     // Читальних маршрутів у контурі магазину немає, тому статус «очима
  445 |     // магазину» видно через відмову повторного переходу arrived → arrived.
  446 |     const seen = await storeSeesStatus(shared.ctx, staffToken, booking.bookingId);
  447 |     expect(seen.status, `магазин має бачити бронювання прибулим, отримано ${JSON.stringify(seen)}`).toBe(409);
  448 |     expect(seen.from).toBe('arrived');
  449 | 
  450 |     // Найпряміший доказ: магазин може почати розвантаження, а це можливо
  451 |     // рівно зі статусу arrived.
  452 |     expect(await storeStartUnloading(shared.ctx, staffToken, booking.bookingId)).toBe(200);
  453 |   });
  454 | 
  455 |   test('D-05 після початку розвантаження водій не може змінити номер замовлення', async ({ page }) => {
  456 |     await openRouteSheet(page, shared.driver);
  457 |     const card = pointCard(page, booking.bookingId);
  458 |     await card.scrollIntoViewIfNeeded();
  459 |     await expect(card.locator('.badge')).toHaveText('Розвантаження');
  460 | 
  461 |     await expect(
  462 |       card.locator('button', { hasText: /№ замовлення/ }),
  463 |       'редагування номера має зникнути після початку розвантаження',
  464 |     ).toHaveCount(0);
  465 | 
  466 |     // І бекенд теж має відхиляти — щоб заборона не трималася лише на UI.
  467 |     const res = await shared.ctx.patch(`${HOSTS.driver}/api/driver/v1/bookings/${booking.bookingId}`, {
  468 |       headers: { Authorization: `Bearer ${driverToken}` },
  469 |       data: { orderId: 'UITEST-ПІСЛЯ-РОЗВАНТАЖЕННЯ' },
  470 |     });
  471 |     expect(res.status()).toBe(422);
  472 |     expect((await res.json()).detail).toContain('до початку розвантаження');
  473 |   });
  474 | });
  475 | 
  476 | // ---------------------------------------------------------------------------
  477 | // D-04. Доступність «На місці» за часом
  478 | // ---------------------------------------------------------------------------
  479 | 
  480 | test.describe('D-04 доступність відмітки за часом', () => {
  481 |   test('D-04 відмітити прибуття на завтрашню точку не можна', async ({ page }) => {
  482 |     await openRouteSheet(page, shared.driver);
  483 |     await page.locator('nav.chips button.chip', { hasText: 'Завтра' }).click();
  484 |     await page.waitForLoadState('networkidle');
  485 | 
  486 |     const card = pointCard(page, shared.next.bookingId);
  487 |     await expect(card).toBeVisible();
  488 |     expect(
  489 |       await card.locator('button.btn.arrive').count(),
  490 |       `точка запланована на ${shared.next.date} о ${shared.next.localTime}; ` +
  491 |         'відмітка «На місці» за добу до слоту створює хибне прибуття в черзі магазину',
  492 |     ).toBe(0);
  493 |   });
  494 | 
  495 |   test('D-04 бекенд теж не приймає прибуття на завтрашню точку', async () => {
  496 |     const probe = await createBooking(shared.ctx, shared.supplierToken, {
  497 |       date: shared.tomorrow,
  498 |       driverId: shared.driver.id,
  499 |       label: `${mark()}-рано`,
  500 |       which: 'last',
  501 |       stores: shared.stores,
  502 |     });
  503 |     const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
  504 |     const res = await shared.ctx.post(`${HOSTS.driver}/api/driver/v1/bookings/${probe.bookingId}/arrived`, {
  505 |       headers: { Authorization: `Bearer ${driverToken}` },
  506 |       data: {},
  507 |     });
  508 |     expect(
  509 |       res.status(),
  510 |       `бронювання на ${probe.date} ${probe.localTime}: відмітка прибуття за добу наперед має бути відхилена, ` +
  511 |         `отримано ${res.status()}`,
> 512 |     ).not.toBe(200);
      |           ^ Error: бронювання на 2026-08-29 13:30: відмітка прибуття за добу наперед має бути відхилена, отримано 200
  513 |   });
  514 | });
  515 | 
  516 | // ---------------------------------------------------------------------------
  517 | // D-06. Затримка
  518 | // ---------------------------------------------------------------------------
  519 | 
  520 | test.describe('D-06 Повідомлення про затримку', () => {
  521 | 
  522 |   let booking: TestBooking;
  523 | 
  524 |   test.beforeAll(async () => {
  525 |     booking = await createBooking(shared.ctx, shared.supplierToken, {
  526 |       date: shared.today,
  527 |       driverId: shared.driver.id,
  528 |       label: `${mark()}-затримка`,
  529 |       which: 'last',
  530 |       stores: shared.stores,
  531 |     });
  532 |   });
  533 | 
  534 |   test('D-06 причина з довідника і новий час прибуття', async ({ page }) => {
  535 |     await openRouteSheet(page, shared.driver);
  536 |     const card = pointCard(page, booking.bookingId);
  537 |     await card.scrollIntoViewIfNeeded();
  538 |     await card.locator('button', { hasText: 'Повідомити про затримку' }).click();
  539 | 
  540 |     const sheet = page.locator('section.sheet[role=dialog]');
  541 |     await expect(sheet).toBeVisible();
  542 |     const sheetText = await sheet.innerText();
  543 |     for (const reason of ['Затори', 'Поломка', 'Затримка на попередній точці', 'Інше']) {
  544 |       expect(sheetText, `у довіднику причин має бути «${reason}»`).toContain(reason);
  545 |     }
  546 | 
  547 |     await sheet.locator('button.sheet-item', { hasText: 'Затори' }).click();
  548 |     await sheet.locator('button.chip', { hasText: '+30 хв' }).click();
  549 | 
  550 |     const [response] = await Promise.all([
  551 |       page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}/delay`)),
  552 |       sheet.locator('button.sheet-item.primary').click(),
  553 |     ]);
  554 |     expect(response.status(), `бекенд відповів: ${await response.text()}`).toBe(200);
  555 | 
  556 |     const body = await response.json();
  557 |     expect(body.delayed?.flag, 'бронювання має бути позначене як затримане').toBe(true);
  558 |     expect(body.delayed?.reason).toBe('затори');
  559 |     expect(Date.parse(body.delayed?.eta), 'новий час прибуття має бути в майбутньому').toBeGreaterThan(Date.now());
  560 | 
  561 |     await expect(page.locator('.toast')).toContainText('Затримку передано магазину');
  562 |     await expect(card).toContainText('Затримка');
  563 |   });
  564 | 
  565 |   test('D-06 причина «Інше» вимагає коментаря', async ({ page }) => {
  566 |     await openRouteSheet(page, shared.driver);
  567 |     const card = pointCard(page, booking.bookingId);
  568 |     await card.scrollIntoViewIfNeeded();
  569 |     await card.locator('button', { hasText: 'Повідомити про затримку' }).click();
  570 | 
  571 |     const sheet = page.locator('section.sheet[role=dialog]');
  572 |     await sheet.locator('button.sheet-item', { hasText: 'Інше' }).click();
  573 |     await sheet.locator('button.chip', { hasText: '+1 год' }).click();
  574 | 
  575 |     const textarea = sheet.locator('textarea.sheet-input');
  576 |     await expect(textarea, 'для «Інше» має зʼявитися поле коментаря').toBeVisible();
  577 |     await expect(
  578 |       sheet.locator('button.sheet-item.primary'),
  579 |       'без коментаря надіслати не можна',
  580 |     ).toBeDisabled();
  581 | 
  582 |     const comment = 'UITEST: об’їзд через перекриту вулицю';
  583 |     await textarea.fill(comment);
  584 |     const [response] = await Promise.all([
  585 |       page.waitForResponse((r) => r.url().includes(`/bookings/${booking.bookingId}/delay`)),
  586 |       sheet.locator('button.sheet-item.primary').click(),
  587 |     ]);
  588 |     expect(response.status(), `бекенд відповів: ${await response.text()}`).toBe(200);
  589 | 
  590 |     const body = await response.json();
  591 |     expect(body.delayed?.reason, 'коментар має дійти до магазину разом із причиною').toContain(comment);
  592 |     await expect(page.locator('.toast')).toContainText('Затримку передано магазину');
  593 |   });
  594 | });
  595 | 
  596 | // ---------------------------------------------------------------------------
  597 | // D-07. Офлайн
  598 | // ---------------------------------------------------------------------------
  599 | 
  600 | test.describe('D-07 Робота без звʼязку', () => {
  601 | 
  602 |   let booking: TestBooking;
  603 | 
  604 |   test.beforeAll(async () => {
  605 |     booking = await createBooking(shared.ctx, shared.supplierToken, {
  606 |       date: shared.today,
  607 |       driverId: shared.driver.id,
  608 |       label: `${mark()}-офлайн`,
  609 |       which: 'last',
  610 |       stores: shared.stores,
  611 |     });
  612 |   });
```