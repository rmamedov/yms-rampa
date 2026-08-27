# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 22-supplier-booking.spec.ts >> S-06 Бронювання >> S-06.5 номер замовлення понад 64 символи не має мовчки обрізатись
- Location: tests/22-supplier-booking.spec.ts:218:7

# Error details

```
Error: введено 77 символів, у полі лишилось 64, повідомлення: «». Користувач має або бачити свій текст цілком, або отримати відмову — мовчазне обрізання псує дані.

expect(received).toBe(expected) // Object.is equality

Expected: true
Received: false
```

# Test source

```ts
  133 | 
  134 |     for (const [value, expectError] of [
  135 |       ['0', true],
  136 |       ['1', false],
  137 |       ['33', false],
  138 |       ['34', true],
  139 |       ['', true],
  140 |     ] as const) {
  141 |       await page.locator('#pallets').fill(value);
  142 |       await page.waitForTimeout(200);
  143 |       const errors = await panelErrors(page);
  144 |       if (expectError && value !== '') {
  145 |         expect(errors.join(' | '), `палети «${value}» мають бути відхилені`).toContain('Вкажіть від 1 до 33 палет');
  146 |       }
  147 |       if (!expectError) {
  148 |         expect(errors, `палети «${value}» мають прийматись без помилок`).toHaveLength(0);
  149 |       }
  150 |       expect(await submitDisabled(page), `кнопка бронювання при палетах «${value}»`).toBe(expectError);
  151 |     }
  152 | 
  153 |     await page.locator('.panel__close').click();
  154 |   });
  155 | 
  156 |   test('S-06.3 держномер: довжина, символи і нормалізація регістру', async ({ page }) => {
  157 |     await loginSupplier(page);
  158 |     const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
  159 |     await holdFreeSlot(page, store, workingDay(1));
  160 | 
  161 |     await page.locator('.panel button:has-text("Додати нове авто")').click();
  162 |     const plate = page.locator('#new-plate');
  163 | 
  164 |     await plate.fill('AB1');
  165 |     await page.waitForTimeout(200);
  166 |     expect(await fieldError(page, '#new-plate'), 'три символи — замало').toContain(
  167 |       'Держномер має містити від 4 до 12 символів',
  168 |     );
  169 | 
  170 |     await plate.fill('ABCDEFGHIJKLM');
  171 |     await page.waitForTimeout(200);
  172 |     expect(await fieldError(page, '#new-plate'), 'тринадцять символів — забагато').toContain(
  173 |       'Держномер має містити від 4 до 12 символів',
  174 |     );
  175 | 
  176 |     await plate.fill('AB#123');
  177 |     await page.waitForTimeout(200);
  178 |     expect(await fieldError(page, '#new-plate'), 'спецсимволи заборонені').toContain(
  179 |       'Держномер може містити лише літери та цифри',
  180 |     );
  181 | 
  182 |     await plate.fill('  aa 12 34 bc  ');
  183 |     await page.waitForTimeout(200);
  184 |     expect(await fieldError(page, '#new-plate'), 'пробіли і нижній регістр — валідне значення').toBe('');
  185 | 
  186 |     await page.locator('.panel__close').click();
  187 |   });
  188 | 
  189 |   test('S-06.4 тоннаж понад ліміт філії відхиляється з назвою ліміту', async ({ page }) => {
  190 |     await loginSupplier(page);
  191 |     // Найлегша філія Харкова — на ній ліміт видно найкраще.
  192 |     const store = [...kharkiv].sort((a, b) => a.maxVehicleWeightTons - b.maxVehicleWeightTons)[0];
  193 |     await holdFreeSlot(page, store, workingDay(1));
  194 | 
  195 |     await page.locator('.panel button:has-text("Додати нове авто")').click();
  196 |     await page.locator('#new-plate').fill(uitestPlate(stamp()));
  197 | 
  198 |     await page.locator('#new-weight').fill(String(store.maxVehicleWeightTons + 2.5));
  199 |     await page.waitForTimeout(250);
  200 |     expect((await panelErrors(page)).join(' | '), 'ліміт філії має бути названий числом').toContain(
  201 |       `Авто перевищує максимальну масу для цієї філії — ${store.maxVehicleWeightTons} т`,
  202 |     );
  203 |     expect(await submitDisabled(page), 'із заважким авто бронювати не можна').toBe(true);
  204 | 
  205 |     await page.locator('#new-weight').fill(String(store.maxVehicleWeightTons));
  206 |     await page.waitForTimeout(250);
  207 |     expect(await panelErrors(page), 'рівно ліміт — допустимо').toHaveLength(0);
  208 | 
  209 |     await page.locator('#new-weight').fill('0');
  210 |     await page.waitForTimeout(250);
  211 |     expect((await panelErrors(page)).join(' | '), 'нульова вантажопідйомність').toMatch(
  212 |       /більшою за 0|Вкажіть вантажопідйомність/,
  213 |     );
  214 | 
  215 |     await page.locator('.panel__close').click();
  216 |   });
  217 | 
  218 |   test('S-06.5 номер замовлення понад 64 символи не має мовчки обрізатись', async ({ page }) => {
  219 |     await loginSupplier(page);
  220 |     const store = kharkiv[0];
  221 |     await holdFreeSlot(page, store, workingDay(1));
  222 | 
  223 |     const long = `UITEST-${'X'.repeat(70)}`;
  224 |     await page.locator('#order-id').fill(long);
  225 |     await page.waitForTimeout(250);
  226 | 
  227 |     const value = await page.locator('#order-id').inputValue();
  228 |     const errors = (await panelErrors(page)).join(' | ');
  229 |     expect(
  230 |       value === long || /64/.test(errors),
  231 |       `введено ${long.length} символів, у полі лишилось ${value.length}, повідомлення: «${errors}». ` +
  232 |         'Користувач має або бачити свій текст цілком, або отримати відмову — мовчазне обрізання псує дані.',
> 233 |     ).toBe(true);
      |       ^ Error: введено 77 символів, у полі лишилось 64, повідомлення: «». Користувач має або бачити свій текст цілком, або отримати відмову — мовчазне обрізання псує дані.
  234 | 
  235 |     // Рівно 64 символи — допустима межа.
  236 |     const exact = `UITEST-${'Y'.repeat(57)}`;
  237 |     await page.locator('#order-id').fill(exact);
  238 |     await page.waitForTimeout(200);
  239 |     expect(await page.locator('#order-id').inputValue()).toHaveLength(64);
  240 |     expect(await panelErrors(page)).toHaveLength(0);
  241 | 
  242 |     await page.locator('.panel__close').click();
  243 |   });
  244 | 
  245 |   test('S-06.6 без авто і без палет бронювання недоступне', async ({ page }) => {
  246 |     await loginSupplier(page);
  247 |     const store = kharkiv[0];
  248 |     await holdFreeSlot(page, store, workingDay(1));
  249 | 
  250 |     expect(await submitDisabled(page), 'порожня форма — кнопка вимкнена').toBe(true);
  251 | 
  252 |     await page.locator('#pallets').fill('10');
  253 |     await page.waitForTimeout(200);
  254 |     expect(await submitDisabled(page), 'палети є, авто немає — все ще вимкнена').toBe(true);
  255 | 
  256 |     await page.locator('.panel button:has-text("Додати нове авто")').click();
  257 |     await page.locator('#new-plate').fill(uitestPlate(stamp()));
  258 |     await page.waitForTimeout(200);
  259 |     expect(await submitDisabled(page), 'авто без вантажопідйомності не рахується').toBe(true);
  260 | 
  261 |     await page.locator('#new-weight').fill('3');
  262 |     await page.waitForTimeout(250);
  263 |     expect(await submitDisabled(page), 'усі поля заповнені — можна бронювати').toBe(false);
  264 | 
  265 |     await page.locator('.panel__close').click();
  266 |   });
  267 | 
  268 |   test('S-06.7 бронювання з новим авто: слот стає зайнятим, дані збережені', async ({ page }) => {
  269 |     test.setTimeout(120_000);
  270 |     await loginSupplier(page);
  271 |     const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
  272 |     const date = workingDay(1);
  273 |     const held = await holdFreeSlot(page, store, date);
  274 | 
  275 |     const mark = stamp();
  276 |     const plate = uitestPlate(mark);
  277 |     const orderId = uitestOrderId(mark);
  278 | 
  279 |     await page.locator('.panel button:has-text("Додати нове авто")').click();
  280 |     await page.locator('#new-plate').fill(plate.toLowerCase());
  281 |     await page.locator('#new-brand').fill('UITEST Volvo FH');
  282 |     await page.locator('#new-weight').fill('5');
  283 |     await page.locator('#order-id').fill(orderId);
  284 |     await page.locator('#pallets').fill('12');
  285 |     await page.waitForTimeout(300);
  286 | 
  287 |     const [created] = await Promise.all([
  288 |       page.waitForResponse((r) => r.url().endsWith('/bookings') && r.request().method() === 'POST', {
  289 |         timeout: 30_000,
  290 |       }),
  291 |       page.locator('.panel__foot .btn--primary').click(),
  292 |     ]);
  293 |     expect(created.status(), 'бронювання має створитись').toBe(201);
  294 |     const booking = await created.json();
  295 |     createdBookings.push(booking.id);
  296 |     registerArtifact('booking', booking.id, `${orderId} ${held.slot.slotStart}`);
  297 | 
  298 |     await expect(page.locator('.toast__text').first()).toHaveText('Слот заброньовано');
  299 |     await page.waitForURL(new RegExp(`/route-sheets/${date}`), { timeout: 20_000 });
  300 | 
  301 |     // Дані бронювання в API — саме те, що ввів користувач.
  302 |     const sheet = await api.sheet(date);
  303 |     const point = sheet.points.find((p) => p.bookingId === booking.id);
  304 |     expect(point, 'бронювання має зʼявитись у маршрутному листі').toBeTruthy();
  305 |     expect(point!.orderId).toBe(orderId);
  306 |     expect(point!.palletsCount).toBe(12);
  307 |     expect(point!.plateNumber, 'держномер має зберегтись у верхньому регістрі').toBe(plate);
  308 |     expect(point!.localTime).toBe(held.slot.localStart);
  309 | 
  310 |     // Авто потрапило у довідник постачальника.
  311 |     const vehicle = (await api.vehicles()).find((v) => v.plateNumber === plate);
  312 |     expect(vehicle, 'нове авто має зʼявитись у довіднику').toBeTruthy();
  313 |     createdVehicles.push(vehicle!.id);
  314 | 
  315 |     // Слот у сітці став зайнятим і неклікабельним.
  316 |     await goto(page, `/booking/stores/${store.storeId}`);
  317 |     await selectGridDate(page, date);
  318 |     const cell = cellAt(page, `${held.slot.localStart}`, held.column);
  319 |     await expect(cell.locator('.slot')).toHaveText('Зайнято');
  320 |     expect(await cell.locator('button.slot').count(), 'зайнятий слот не має бути кнопкою').toBe(0);
  321 |   });
  322 | 
  323 |   test('S-06.8 авто з довідника: пошук за держномером і бронювання', async ({ page }) => {
  324 |     test.setTimeout(120_000);
  325 |     const mark = stamp();
  326 |     const plate = uitestPlate(mark);
  327 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand: 'UITEST Scania' });
  328 |     createdVehicles.push(vehicle.id);
  329 | 
  330 |     await loginSupplier(page);
  331 |     const store = kharkiv.find((s) => s.ramps.length > 1) ?? kharkiv[0];
  332 |     const date = workingDay(2);
  333 |     const held = await holdFreeSlot(page, store, date);
```