# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 23-supplier-directories.spec.ts >> S-09 Довідник авто >> S-09.10 пошук за маркою з кількох слів знаходить авто
- Location: tests/23-supplier-directories.spec.ts:293:7

# Error details

```
Error: поле обіцяє «Пошук за держномером або маркою», марка авто — «Renault 4439»; пошук за нею має знаходити авто

expect(locator).toHaveCount(expected) failed

Locator:  locator('.table tbody tr')
Expected: 1
Received: 0
Timeout:  15000ms

Call log:
  - поле обіцяє «Пошук за держномером або маркою», марка авто — «Renault 4439»; пошук за нею має знаходити авто with timeout 15000ms
  - waiting for locator('.table tbody tr')
    34 × locator resolved to 0 elements
       - unexpected value "0"

```

# Test source

```ts
  210 | 
  211 |     const [response] = await Promise.all([
  212 |       page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'DELETE'),
  213 |       page.locator('.modal__window button:has-text("Видалити")').click(),
  214 |     ]);
  215 | 
  216 |     expect(
  217 |       response.status(),
  218 |       'авто без жодного бронювання має видалятись; ' +
  219 |         `сервер відповів ${response.status()}: ${normalizedText(await response.text()).slice(0, 200)}`,
  220 |     ).toBe(204);
  221 |     await expect(row(page, plate)).toHaveCount(0);
  222 |     expect((await api.vehicles()).some((v) => v.id === vehicle.id)).toBe(false);
  223 |   });
  224 | 
  225 |   test('S-09.8 авто з активним бронюванням видалити не можна, деактивувати — можна', async ({ page }) => {
  226 |     const plate = uitestPlate(stamp());
  227 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3 });
  228 |     createdVehicles.push(vehicle.id);
  229 | 
  230 |     const store = kharkiv[0];
  231 |     const date = workingDay(1);
  232 |     const grid = await api.slots(store.storeId, date);
  233 |     const free = grid.slots.filter((s) => s.selectable).pop();
  234 |     expect(free, 'потрібен вільний слот').toBeTruthy();
  235 |     const booking = await api.createBooking({
  236 |       storeId: store.storeId,
  237 |       rampId: free!.rampId,
  238 |       slotStart: free!.slotStart,
  239 |       plateNumber: plate,
  240 |       weightTons: 3,
  241 |       palletsCount: 4,
  242 |       orderId: 'UITEST-veh-lock',
  243 |     });
  244 |     createdBookings.push(booking.id);
  245 | 
  246 |     await loginSupplier(page);
  247 |     await goto(page, '/vehicles');
  248 |     await row(page, plate).locator('button:has-text("Видалити")').click();
  249 |     await page.locator('.modal__window button:has-text("Видалити")').click();
  250 | 
  251 |     await expect(page.locator('.toast__text').first()).toContainText(
  252 |       'Авто привʼязане до активних бронювань — доступна лише деактивація',
  253 |     );
  254 |     await expect(row(page, plate), 'авто має лишитись у довіднику').toBeVisible();
  255 | 
  256 |     await row(page, plate).locator('button:has-text("Деактивувати")').click();
  257 |     await expect(row(page, plate)).toContainText('Деактивоване');
  258 |   });
  259 | 
  260 |   test('S-09.9 пошук у довіднику за номером і за маркою', async ({ page }) => {
  261 |     const mark = stamp();
  262 |     const plate = uitestPlate(mark);
  263 |     const brand = `UITEST Renault ${mark}`;
  264 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
  265 |     createdVehicles.push(vehicle.id);
  266 | 
  267 |     await loginSupplier(page);
  268 |     await goto(page, '/vehicles');
  269 |     const search = page.locator('.vehicles__search');
  270 |     const rows = page.locator('.table tbody tr');
  271 | 
  272 |     const vehicles = await api.vehicles();
  273 |     await search.fill(mark);
  274 |     await page.waitForTimeout(300);
  275 |     const expected = vehicles.filter((v) => v.plateNumber.includes(mark) || (v.brand ?? '').includes(mark)).length;
  276 |     expect(await rows.count(), `пошук за фрагментом номера «${mark}»`).toBe(expected);
  277 | 
  278 |     // Держномер із пробілами — так його диктують і записують.
  279 |     await search.fill(`${plate.slice(0, 2)} ${plate.slice(2, 6)} ${plate.slice(6)}`);
  280 |     await page.waitForTimeout(300);
  281 |     await expect(rows, 'пошук за номером із пробілами').toHaveCount(1);
  282 | 
  283 |     await search.fill('renault');
  284 |     await page.waitForTimeout(300);
  285 |     const byBrandWord = vehicles.filter((v) => (v.brand ?? '').toLowerCase().includes('renault')).length;
  286 |     expect(await rows.count(), 'пошук за одним словом марки').toBe(byBrandWord);
  287 | 
  288 |     await search.fill('немаєтакогономера');
  289 |     await page.waitForTimeout(300);
  290 |     await expect(page.locator('.empty-state')).toBeVisible();
  291 |   });
  292 | 
  293 |   test('S-09.10 пошук за маркою з кількох слів знаходить авто', async ({ page }) => {
  294 |     const mark = stamp();
  295 |     const plate = uitestPlate(mark);
  296 |     const brand = `Renault ${mark}`;
  297 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
  298 |     createdVehicles.push(vehicle.id);
  299 | 
  300 |     await loginSupplier(page);
  301 |     await goto(page, '/vehicles');
  302 |     const rows = page.locator('.table tbody tr');
  303 | 
  304 |     await page.locator('.vehicles__search').fill(brand);
  305 |     await page.waitForTimeout(400);
  306 |     await expect(
  307 |       rows,
  308 |       `поле обіцяє «Пошук за держномером або маркою», марка авто — «${brand}»; ` +
  309 |         'пошук за нею має знаходити авто',
> 310 |     ).toHaveCount(1);
      |       ^ Error: поле обіцяє «Пошук за держномером або маркою», марка авто — «Renault 4439»; пошук за нею має знаходити авто
  311 |   });
  312 | });
  313 | 
  314 | test.describe('S-10 Водії', () => {
  315 |   test('S-10.1 X-01 у таблиці всі водії з API', async ({ page }) => {
  316 |     await loginSupplier(page);
  317 |     await goto(page, '/drivers');
  318 | 
  319 |     const drivers = await api.drivers();
  320 |     const rows = page.locator('.table tbody tr');
  321 |     await expect(rows.first()).toBeVisible();
  322 |     expect(await rows.count(), `в API ${drivers.length} водіїв`).toBe(drivers.length);
  323 | 
  324 |     const text = await bodyText(page);
  325 |     for (const driver of drivers) {
  326 |       expect(text, `водій ${driver.lastName} має бути у списку`).toContain(driver.lastName);
  327 |       expect(text, `телефон ${driver.phone} має бути показаний`).toContain(driver.phone);
  328 |     }
  329 |   });
  330 | 
  331 |   test('S-10.2 телефон приймається у всіх поширених форматах', async ({ page }) => {
  332 |     test.setTimeout(180_000);
  333 |     await loginSupplier(page);
  334 | 
  335 |     const base = stamp();
  336 |     const digits = (index: number) => `${base.slice(0, 3)}${index}`;
  337 |     const variants = [
  338 |       { label: '0XXXXXXXXX', value: (d: string) => `099000${d}` },
  339 |       { label: '+380XXXXXXXXX', value: (d: string) => `+38099000${d}` },
  340 |       { label: '380XXXXXXXXX', value: (d: string) => `38099000${d}` },
  341 |       { label: 'з пробілами і дефісами', value: (d: string) => `0 (99) 000-${d.slice(0, 2)}-${d.slice(2)}` },
  342 |     ];
  343 | 
  344 |     for (const [index, variant] of variants.entries()) {
  345 |       const d = digits(index);
  346 |       const expectedPhone = uitestPhone(d);
  347 |       await goto(page, '/drivers');
  348 |       await page.locator('button:has-text("Додати водія")').click();
  349 | 
  350 |       await page.locator('#drv-phone').fill(variant.value(d));
  351 |       await page.locator('#drv-last').fill(`UITEST-${d}`);
  352 |       await page.locator('#drv-first').fill('Формат');
  353 |       await page.waitForTimeout(200);
  354 |       await expect(
  355 |         page.locator('.modal__window button:has-text("Зберегти")'),
  356 |         `формат «${variant.label}» має прийматись`,
  357 |       ).toBeEnabled();
  358 | 
  359 |       const [response] = await Promise.all([
  360 |         page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
  361 |         page.locator('.modal__window button:has-text("Зберегти")').click(),
  362 |       ]);
  363 |       expect(response.status(), `формат «${variant.label}»: створення водія`).toBe(201);
  364 |       const created = await response.json();
  365 |       createdDrivers.push(created.driverId ?? created.id);
  366 |       registerArtifact('driver', created.driverId ?? created.id, expectedPhone);
  367 | 
  368 |       await page.locator('.modal__window button:has-text("Закрити")').click();
  369 |       await expect(
  370 |         row(page, expectedPhone),
  371 |         `телефон «${variant.value(d)}» має зберегтись як ${expectedPhone}`,
  372 |       ).toBeVisible();
  373 |     }
  374 |   });
  375 | 
  376 |   test('S-10.3 дублікат телефону відхиляється зрозумілим повідомленням', async ({ page }) => {
  377 |     await loginSupplier(page);
  378 |     const existing = (await api.drivers()).find((d) => d.phone.startsWith('+38099000')) ?? (await api.drivers())[0];
  379 | 
  380 |     await goto(page, '/drivers');
  381 |     await page.locator('button:has-text("Додати водія")').click();
  382 |     await page.locator('#drv-phone').fill(existing.phone);
  383 |     await page.locator('#drv-last').fill('UITEST-дубль');
  384 |     await page.locator('#drv-first').fill('Тест');
  385 |     await page.waitForTimeout(200);
  386 | 
  387 |     const [response] = await Promise.all([
  388 |       page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
  389 |       page.locator('.modal__window button:has-text("Зберегти")').click(),
  390 |     ]);
  391 |     expect(response.status(), 'дублікат телефону має відхилятись сервером').toBe(409);
  392 |     await expect(page.locator('.toast__text').first()).toContainText(
  393 |       'Водій з таким телефоном уже зареєстрований',
  394 |     );
  395 |   });
  396 | 
  397 |   test('S-10.4 валідація телефону та ПІБ', async ({ page }) => {
  398 |     await loginSupplier(page);
  399 |     await goto(page, '/drivers');
  400 |     await page.locator('button:has-text("Додати водія")').click();
  401 | 
  402 |     const save = page.locator('.modal__window button:has-text("Зберегти")');
  403 |     await expect(save, 'без ПІБ зберегти не можна').toBeDisabled();
  404 |     await expect(page.locator('.modal__window')).toContainText('Вкажіть прізвище');
  405 | 
  406 |     await page.locator('#drv-phone').fill('+38099');
  407 |     await page.waitForTimeout(200);
  408 |     await expect(page.locator('.modal__window')).toContainText('Формат телефону: +380XXXXXXXXX');
  409 |     await expect(save).toBeDisabled();
  410 | 
```