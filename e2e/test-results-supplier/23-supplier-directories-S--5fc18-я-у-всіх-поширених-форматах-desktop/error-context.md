# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 23-supplier-directories.spec.ts >> S-10 Водії >> S-10.2 телефон приймається у всіх поширених форматах
- Location: tests/23-supplier-directories.spec.ts:331:7

# Error details

```
Error: формат «380XXXXXXXXX»: створення водія

expect(received).toBe(expected) // Object.is equality

Expected: 201
Received: 409
```

# Test source

```ts
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
  310 |     ).toHaveCount(1);
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
> 363 |       expect(response.status(), `формат «${variant.label}»: створення водія`).toBe(201);
      |                                                                               ^ Error: формат «380XXXXXXXXX»: створення водія
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
  411 |     await page.locator('#drv-phone').fill('+380990001234567');
  412 |     await page.waitForTimeout(200);
  413 |     await expect(page.locator('.modal__window'), 'задовгий номер теж відхиляється').toContainText(
  414 |       'Формат телефону: +380XXXXXXXXX',
  415 |     );
  416 | 
  417 |     await page.locator('#drv-phone').fill(uitestPhone(stamp()));
  418 |     await page.locator('#drv-last').fill('UITEST');
  419 |     await page.waitForTimeout(200);
  420 |     await expect(page.locator('.modal__window'), 'імʼя лишається обовʼязковим').toContainText('Вкажіть імʼя');
  421 |     await expect(save).toBeDisabled();
  422 |   });
  423 | 
  424 |   test('S-10.5 пароль показується один раз і зникає після закриття', async ({ page }) => {
  425 |     await loginSupplier(page);
  426 |     await goto(page, '/drivers');
  427 | 
  428 |     const mark = stamp();
  429 |     const phone = uitestPhone(mark);
  430 |     await page.locator('button:has-text("Додати водія")').click();
  431 |     await page.locator('#drv-phone').fill(phone);
  432 |     await page.locator('#drv-last').fill(`UITEST-${mark}`);
  433 |     await page.locator('#drv-first').fill('Пароль');
  434 |     await page.waitForTimeout(200);
  435 | 
  436 |     const [response] = await Promise.all([
  437 |       page.waitForResponse((r) => r.url().endsWith('/drivers') && r.request().method() === 'POST'),
  438 |       page.locator('.modal__window button:has-text("Зберегти")').click(),
  439 |     ]);
  440 |     const created = await response.json();
  441 |     createdDrivers.push(created.driverId ?? created.id);
  442 |     registerArtifact('driver', created.driverId ?? created.id, phone);
  443 | 
  444 |     const passwordBox = page.locator('.drivers__password');
  445 |     await expect(passwordBox).toBeVisible();
  446 |     const password = normalizedText(await passwordBox.innerText());
  447 |     expect(password.length, 'пароль має бути непорожнім').toBeGreaterThan(6);
  448 |     await expect(page.locator('.modal__window')).toContainText('Запишіть пароль — повторно він не показується');
  449 |     await expect(page.locator('.modal__window'), 'логін водія — його телефон').toContainText(phone);
  450 | 
  451 |     await page.locator('.modal__window button:has-text("Закрити")').click();
  452 |     await expect(page.locator('.drivers__password')).toHaveCount(0);
  453 |     expect(await bodyText(page), 'після закриття пароля на екрані бути не має').not.toContain(password);
  454 | 
  455 |     await page.reload();
  456 |     await page.waitForLoadState('networkidle');
  457 |     expect(await bodyText(page), 'і після перезавантаження теж').not.toContain(password);
  458 |   });
  459 | 
  460 |   test('S-10.6 перегенерація пароля видає новий пароль', async ({ page }) => {
  461 |     await loginSupplier(page);
  462 |     await goto(page, '/drivers');
  463 | 
```