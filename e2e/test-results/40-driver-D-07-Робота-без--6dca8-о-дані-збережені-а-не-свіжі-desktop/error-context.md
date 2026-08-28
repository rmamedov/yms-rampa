# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 40-driver.spec.ts >> D-07 Робота без звʼязку >> D-07 без звʼязку водій попереджений, що дані збережені, а не свіжі
- Location: tests/40-driver.spec.ts:626:7

# Error details

```
Error: екран без звʼязку не позначений як збережений; показано: «Маршрутний лист +380 99 000 10 21 ☰ Сьогодні 4 Завтра 1 Точок: 4 Палет: 44 08:00 Очікує виїзду Сільпо, вул. Берковецька, 6Д Київ, вул. Берковецька, 6Д РАМПА 2 ПАЛЕТ 12 АВТО UT5953XX № ЗАМОВЛЕННЯ UITES»

expect(locator).toBeVisible() failed

Locator: locator('.banner.offline')
Expected: visible
Timeout: 15000ms
Error: element(s) not found

Call log:
  - екран без звʼязку не позначений як збережений; показано: «Маршрутний лист +380 99 000 10 21 ☰ Сьогодні 4 Завтра 1 Точок: 4 Палет: 44 08:00 Очікує виїзду Сільпо, вул. Берковецька, 6Д Київ, вул. Берковецька, 6Д РАМПА 2 ПАЛЕТ 12 АВТО UT5953XX № ЗАМОВЛЕННЯ UITES» with timeout 15000ms
  - waiting for locator('.banner.offline')

```

```yaml
- banner:
  - heading "Маршрутний лист" [level=1]
  - paragraph: +380 99 000 10 21
  - button "Меню"
- navigation "Маршрутний лист":
  - button "Сьогодні 4"
  - button "Завтра 1"
- text: "Точок: 4 Палет: 44"
- list:
  - listitem:
    - article:
      - text: 08:00 Очікує виїзду
      - heading "Сільпо, вул. Берковецька, 6Д" [level=2]
      - paragraph: Київ, вул. Берковецька, 6Д
      - term: Рампа
      - definition: "2"
      - term: Палет
      - definition: "12"
      - term: Авто
      - definition: UT5953XX
      - term: № замовлення
      - definition: UITEST-28753126-A
      - button "На місці"
      - button "Побудувати маршрут"
      - button "Змінити № замовлення"
      - button "Повідомити про затримку"
  - listitem:
    - article:
      - text: 13:00 Очікує виїзду
      - heading "Сільпо, вул. Берковецька, 6Д" [level=2]
      - paragraph: Київ, вул. Берковецька, 6Д
      - term: Рампа
      - definition: "2"
      - term: Палет
      - definition: "12"
      - term: Авто
      - definition: UT2237XX
      - term: № замовлення
      - definition: UITEST-29410990-офлайн
      - button "На місці"
      - button "Побудувати маршрут"
      - button "Змінити № замовлення"
      - button "Повідомити про затримку"
  - listitem:
    - article:
      - text: 13:30 Очікує виїзду
      - heading "Сільпо, вул. Берковецька, 6Д" [level=2]
      - paragraph: Київ, вул. Берковецька, 6Д
      - status: Затримка · Новий час прибуття — 04:01
      - term: Рампа
      - definition: "1"
      - term: Палет
      - definition: "12"
      - term: Авто
      - definition: UT2431XX
      - term: № замовлення
      - definition: UITEST-28965730-затримка
      - button "На місці"
      - button "Побудувати маршрут"
      - button "Змінити № замовлення"
      - button "Повідомити про затримку"
  - listitem:
    - article:
      - text: 13:30 Очікує виїзду
      - heading "Сільпо, вул. Берковецька, 6Д" [level=2]
      - paragraph: Київ, вул. Берковецька, 6Д
      - term: Рампа
      - definition: "2"
      - term: Палет
      - definition: "8"
      - term: Авто
      - definition: UT8218XX
      - term: № замовлення
      - definition: UITEST-28753126-B
      - button "На місці"
      - button "Побудувати маршрут"
      - button "Змінити № замовлення"
      - button "Повідомити про затримку"
- paragraph: Оновлено 03:01
```

# Test source

```ts
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
  613 | 
  614 |   test('D-07 кешований маршрутний лист відкривається без мережі', async ({ page, context }) => {
  615 |     await openRouteSheet(page, shared.driver);
  616 |     await expect(pointCard(page, booking.bookingId)).toBeVisible();
  617 | 
  618 |     await context.setOffline(true);
  619 |     await page.reload();
  620 |     await page.waitForSelector('ul.points > li', { timeout: 30_000 });
  621 | 
  622 |     await expect(pointCard(page, booking.bookingId), 'кешований лист має відкриватися офлайн').toBeVisible();
  623 |     await context.setOffline(false);
  624 |   });
  625 | 
  626 |   test('D-07 без звʼязку водій попереджений, що дані збережені, а не свіжі', async ({ page, context }) => {
  627 |     await openRouteSheet(page, shared.driver);
  628 | 
  629 |     await context.setOffline(true);
  630 |     await page.reload();
  631 |     await page.waitForSelector('ul.points > li', { timeout: 30_000 });
  632 | 
  633 |     // Маршрут показано без мережі — це добре. Погано, якщо водій цього не знає:
  634 |     // «Оновлено HH:MM» на збережених даних читається як «щойно з сервера».
  635 |     const text = await pageText(page);
  636 |     await expect(
  637 |       page.locator('.banner.offline'),
  638 |       `екран без звʼязку не позначений як збережений; показано: «${text.slice(0, 200)}»`,
> 639 |     ).toBeVisible();
      |       ^ Error: екран без звʼязку не позначений як збережений; показано: «Маршрутний лист +380 99 000 10 21 ☰ Сьогодні 4 Завтра 1 Точок: 4 Палет: 44 08:00 Очікує виїзду Сільпо, вул. Берковецька, 6Д Київ, вул. Берковецька, 6Д РАМПА 2 ПАЛЕТ 12 АВТО UT5953XX № ЗАМОВЛЕННЯ UITES»
  640 |     await context.setOffline(false);
  641 |   });
  642 | 
  643 |   test('D-07 відмітка без мережі стає в чергу і йде на сервер після відновлення', async ({ page, context }) => {
  644 |     await openRouteSheet(page, shared.driver);
  645 |     const card = pointCard(page, booking.bookingId);
  646 |     await card.scrollIntoViewIfNeeded();
  647 | 
  648 |     await context.setOffline(true);
  649 |     await card.locator('button.btn.arrive').click();
  650 | 
  651 |     await expect(card, 'водій має отримати підтвердження, а не мовчання').toContainText(
  652 |       'Відмітку збережено',
  653 |     );
  654 |     await expect(page.locator('.banner.queued')).toContainText('чекають на звʼязок');
  655 | 
  656 |     // Сервер відмітки ще не бачив.
  657 |     const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
  658 |     let points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
  659 |     expect(points.find((p) => p.bookingId === booking.bookingId)?.status).toBe('booked');
  660 | 
  661 |     await context.setOffline(false);
  662 |     await expect(page.locator('.banner.queued'), 'черга має спорожніти після відновлення звʼязку').toHaveCount(0, {
  663 |       timeout: 45_000,
  664 |     });
  665 | 
  666 |     points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
  667 |     expect(
  668 |       points.find((p) => p.bookingId === booking.bookingId)?.status,
  669 |       'відкладена відмітка має дійти до сервера',
  670 |     ).toBe('arrived');
  671 |   });
  672 | });
  673 | 
  674 | // ---------------------------------------------------------------------------
  675 | // RT-04. Автооновлення маршрутного листа
  676 | // ---------------------------------------------------------------------------
  677 | 
  678 | test.describe('RT-04 Автооновлення маршрутного листа', () => {
  679 |   test('RT-04 нова точка зʼявляється у водія без перезаходу', async ({ page }) => {
  680 |     // Полінг за замовчуванням раз на 30 с (environment.pollIntervalMs).
  681 |     test.setTimeout(150_000);
  682 | 
  683 |     await openRouteSheet(page, shared.driver);
  684 |     const before = await page.locator('ul.points > li').count();
  685 |     expect(before, 'на початку у водія вже є точки').toBeGreaterThan(0);
  686 | 
  687 |     const extra = await createBooking(shared.ctx, shared.supplierToken, {
  688 |       date: shared.today,
  689 |       driverId: shared.driver.id,
  690 |       label: `${mark()}-полінг`,
  691 |       which: 'last',
  692 |       stores: shared.stores,
  693 |     });
  694 | 
  695 |     // Джерело істини: у листі вже на одну точку більше.
  696 |     const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
  697 |     const points = await driverRouteSheet(shared.ctx, driverToken, shared.today);
  698 |     expect(points.map((p) => p.bookingId)).toContain(extra.bookingId);
  699 | 
  700 |     await expect(
  701 |       pointCard(page, extra.bookingId),
  702 |       `постачальник додав точку ${extra.orderId} на ${extra.localTime}; ` +
  703 |         'водій має побачити її з чергового оновлення, не перезаходячи в застосунок',
  704 |     ).toBeVisible({ timeout: 90_000 });
  705 |   });
  706 | });
  707 | 
  708 | // ---------------------------------------------------------------------------
  709 | // D-09. Обмеження прав
  710 | // ---------------------------------------------------------------------------
  711 | 
  712 | test.describe('D-09 Обмеження прав водія', () => {
  713 |   test('D-09 водій не бачить чужих точок', async ({ page }) => {
  714 |     await openRouteSheet(page, shared.emptyDriver);
  715 |     const text = await pageText(page);
  716 |     expect(text, 'чуже бронювання не має потрапляти на екран').not.toContain(shared.early.orderId);
  717 |     expect(text).not.toContain(shared.early.plateNumber);
  718 |   });
  719 | 
  720 |   test('D-09 дії над чужою точкою відхиляються', async () => {
  721 |     const foreign = await driverAuth(shared.ctx, shared.emptyDriver.phone, shared.emptyDriver.password);
  722 |     const res = await shared.ctx.post(
  723 |       `${HOSTS.driver}/api/driver/v1/bookings/${shared.early.bookingId}/arrived`,
  724 |       { headers: { Authorization: `Bearer ${foreign}` }, data: {} },
  725 |     );
  726 |     expect(res.status(), 'чуже бронювання має бути недосяжним').toBe(403);
  727 |   });
  728 | 
  729 |   test('D-09 водієві недоступні дії магазину', async () => {
  730 |     const driverToken = await driverAuth(shared.ctx, shared.driver.phone, shared.driver.password);
  731 |     const res = await shared.ctx.post(
  732 |       `${HOSTS.store}/api/store/v1/bookings/${shared.early.bookingId}/unloading`,
  733 |       { headers: { Authorization: `Bearer ${driverToken}` }, data: {} },
  734 |     );
  735 |     expect([401, 403, 404], `отримано ${res.status()}`).toContain(res.status());
  736 |   });
  737 | 
  738 |   test('D-09/X-10 маршрутний лист не має горизонтального скролу на десктопі', async ({ page }) => {
  739 |     await openRouteSheet(page, shared.driver);
```