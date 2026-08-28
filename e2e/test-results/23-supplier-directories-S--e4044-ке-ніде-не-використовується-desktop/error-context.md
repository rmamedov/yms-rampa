# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 23-supplier-directories.spec.ts >> S-09 Довідник авто >> S-09.7 видалення авто, яке ніде не використовується
- Location: tests/23-supplier-directories.spec.ts:196:7

# Error details

```
Error: авто без жодного бронювання має видалятись; сервер відповів 409: {"type":"about:blank","title":"\u041a\u043e\u043d\u0444\u043b\u0456\u043a\u0442 \u0441\u0442\u0430\u043d\u0443","status":409,"detail":"\u0410\u0432\u0442\u043e \u043f\u0440\u0438\u0432\u0027\u044f\u04

expect(received).toBe(expected) // Object.is equality

Expected: 204
Received: 409
```

# Test source

```ts
  115 | 
  116 |     const save = page.locator('.modal__window button:has-text("Зберегти")');
  117 |     await expect(save, 'порожня форма не зберігається').toBeDisabled();
  118 |     await expect(page.locator('.modal__window')).toContainText('Вкажіть держномер');
  119 | 
  120 |     await saveVehicleForm(page, { plate: 'AB1', weight: '5' });
  121 |     await expect(page.locator('.modal__window')).toContainText('Держномер має містити від 4 до 12 символів');
  122 |     await expect(save).toBeDisabled();
  123 | 
  124 |     await saveVehicleForm(page, { plate: await api.freePlate(), weight: '' });
  125 |     await expect(page.locator('.modal__window')).toContainText('Вкажіть вантажопідйомність');
  126 |     await expect(save).toBeDisabled();
  127 | 
  128 |     await saveVehicleForm(page, { weight: '0' });
  129 |     await expect(page.locator('.modal__window')).toContainText('Вантажопідйомність має бути більшою за 0');
  130 |     await expect(save).toBeDisabled();
  131 | 
  132 |     await saveVehicleForm(page, { weight: '3' });
  133 |     await expect(save, 'коректні дані — можна зберігати').toBeEnabled();
  134 |   });
  135 | 
  136 |   test('S-09.4 дублікат держномера в межах постачальника відхиляється', async ({ page }) => {
  137 |     const plate = await api.freePlate();
  138 |     const existing = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  139 |     createdVehicles.push(existing.id);
  140 | 
  141 |     await loginSupplier(page);
  142 |     await goto(page, '/vehicles');
  143 |     await page.locator('button:has-text("Додати авто")').click();
  144 |     await saveVehicleForm(page, { plate, weight: '4' });
  145 | 
  146 |     await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  147 |     await expect(page.locator('.modal__window button:has-text("Зберегти")')).toBeDisabled();
  148 | 
  149 |     // Той самий номер у нижньому регістрі — теж дублікат.
  150 |     await saveVehicleForm(page, { plate: plate.toLowerCase() });
  151 |     await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  152 |   });
  153 | 
  154 |   test('S-09.5 редагування зберігає нові марку і вантажопідйомність', async ({ page }) => {
  155 |     const plate = await api.freePlate();
  156 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand: 'UITEST старий' });
  157 |     createdVehicles.push(vehicle.id);
  158 | 
  159 |     await loginSupplier(page);
  160 |     await goto(page, '/vehicles');
  161 |     await row(page, plate).locator('button:has-text("Редагувати")').click();
  162 |     await expect(page.locator('.modal__title')).toHaveText('Редагування авто');
  163 |     expect(await page.locator('#veh-plate').inputValue(), 'форма має відкритись із поточними даними').toBe(plate);
  164 | 
  165 |     await saveVehicleForm(page, { brand: 'UITEST новий', weight: '18.5' });
  166 |     await Promise.all([
  167 |       page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'PATCH'),
  168 |       page.locator('.modal__window button:has-text("Зберегти")').click(),
  169 |     ]);
  170 | 
  171 |     await expect(row(page, plate)).toContainText('UITEST новий');
  172 |     const updated = (await api.vehicles()).find((v) => v.id === vehicle.id);
  173 |     expect(updated?.brand).toBe('UITEST новий');
  174 |     expect(updated?.weightTons).toBe(18.5);
  175 |   });
  176 | 
  177 |   test('S-09.6 деактивація і повернення авто в роботу', async ({ page }) => {
  178 |     const plate = await api.freePlate();
  179 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  180 |     createdVehicles.push(vehicle.id);
  181 | 
  182 |     await loginSupplier(page);
  183 |     await goto(page, '/vehicles');
  184 | 
  185 |     await row(page, plate).locator('button:has-text("Деактивувати")').click();
  186 |     await expect(page.locator('.toast__text').first()).toHaveText('Авто деактивовано');
  187 |     await expect(row(page, plate)).toContainText('Деактивоване');
  188 |     expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(false);
  189 | 
  190 |     await row(page, plate).locator('button:has-text("Активувати")').click();
  191 |     await page.waitForTimeout(800);
  192 |     await expect(row(page, plate)).toContainText('Активне');
  193 |     expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(true);
  194 |   });
  195 | 
  196 |   test('S-09.7 видалення авто, яке ніде не використовується', async ({ page }) => {
  197 |     const plate = await api.freePlate();
  198 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  199 |     createdVehicles.push(vehicle.id);
  200 | 
  201 |     await loginSupplier(page);
  202 |     await goto(page, '/vehicles');
  203 |     await row(page, plate).locator('button:has-text("Видалити")').click();
  204 |     await expect(page.locator('.modal__window')).toContainText(`Видалити авто ${plate}?`);
  205 | 
  206 |     const [response] = await Promise.all([
  207 |       page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'DELETE'),
  208 |       page.locator('.modal__window button:has-text("Видалити")').click(),
  209 |     ]);
  210 | 
  211 |     expect(
  212 |       response.status(),
  213 |       'авто без жодного бронювання має видалятись; ' +
  214 |         `сервер відповів ${response.status()}: ${normalizedText(await response.text()).slice(0, 200)}`,
> 215 |     ).toBe(204);
      |       ^ Error: авто без жодного бронювання має видалятись; сервер відповів 409: {"type":"about:blank","title":"\u041a\u043e\u043d\u0444\u043b\u0456\u043a\u0442 \u0441\u0442\u0430\u043d\u0443","status":409,"detail":"\u0410\u0432\u0442\u043e \u043f\u0440\u0438\u0432\u0027\u044f\u04
  216 |     await expect(row(page, plate)).toHaveCount(0);
  217 |     expect((await api.vehicles()).some((v) => v.id === vehicle.id)).toBe(false);
  218 |   });
  219 | 
  220 |   test('S-09.8 авто з активним бронюванням видалити не можна, деактивувати — можна', async ({ page }) => {
  221 |     const plate = await api.freePlate();
  222 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3 });
  223 |     createdVehicles.push(vehicle.id);
  224 | 
  225 |     const store = kharkiv[0];
  226 |     const date = workingDay(1);
  227 |     const grid = await api.slots(store.storeId, date);
  228 |     const free = grid.slots.filter((s) => s.selectable).pop();
  229 |     expect(free, 'потрібен вільний слот').toBeTruthy();
  230 |     const booking = await api.createBooking({
  231 |       storeId: store.storeId,
  232 |       rampId: free!.rampId,
  233 |       slotStart: free!.slotStart,
  234 |       plateNumber: plate,
  235 |       weightTons: 3,
  236 |       palletsCount: 4,
  237 |       orderId: 'UITEST-veh-lock',
  238 |     });
  239 |     createdBookings.push(booking.id);
  240 | 
  241 |     await loginSupplier(page);
  242 |     await goto(page, '/vehicles');
  243 |     await row(page, plate).locator('button:has-text("Видалити")').click();
  244 |     await page.locator('.modal__window button:has-text("Видалити")').click();
  245 | 
  246 |     await expect(page.locator('.toast__text').first()).toContainText(
  247 |       'Авто привʼязане до активних бронювань — доступна лише деактивація',
  248 |     );
  249 |     await expect(row(page, plate), 'авто має лишитись у довіднику').toBeVisible();
  250 | 
  251 |     await row(page, plate).locator('button:has-text("Деактивувати")').click();
  252 |     await expect(row(page, plate)).toContainText('Деактивоване');
  253 |   });
  254 | 
  255 |   test('S-09.9 пошук у довіднику за номером і за маркою', async ({ page }) => {
  256 |     const plate = await api.freePlate();
  257 |     const mark = plate.slice(2, 6);
  258 |     const brand = `UITEST Renault ${mark}`;
  259 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
  260 |     createdVehicles.push(vehicle.id);
  261 | 
  262 |     await loginSupplier(page);
  263 |     await goto(page, '/vehicles');
  264 |     const search = page.locator('.vehicles__search');
  265 |     const rows = page.locator('.table tbody tr');
  266 | 
  267 |     const vehicles = await api.vehicles();
  268 |     await search.fill(mark);
  269 |     await page.waitForTimeout(300);
  270 |     const expected = vehicles.filter((v) => v.plateNumber.includes(mark) || (v.brand ?? '').includes(mark)).length;
  271 |     expect(await rows.count(), `пошук за фрагментом номера «${mark}»`).toBe(expected);
  272 | 
  273 |     // Держномер із пробілами — так його диктують і записують.
  274 |     await search.fill(`${plate.slice(0, 2)} ${plate.slice(2, 6)} ${plate.slice(6)}`);
  275 |     await page.waitForTimeout(300);
  276 |     await expect(rows, 'пошук за номером із пробілами').toHaveCount(1);
  277 | 
  278 |     await search.fill('renault');
  279 |     await page.waitForTimeout(300);
  280 |     const byBrandWord = vehicles.filter((v) => (v.brand ?? '').toLowerCase().includes('renault')).length;
  281 |     expect(await rows.count(), 'пошук за одним словом марки').toBe(byBrandWord);
  282 | 
  283 |     await search.fill('немаєтакогономера');
  284 |     await page.waitForTimeout(300);
  285 |     await expect(page.locator('.empty-state')).toBeVisible();
  286 |   });
  287 | 
  288 |   test('S-09.10 пошук за маркою з кількох слів знаходить авто', async ({ page }) => {
  289 |     const plate = await api.freePlate();
  290 |     const mark = plate.slice(2, 6);
  291 |     const brand = `Renault ${mark}`;
  292 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
  293 |     createdVehicles.push(vehicle.id);
  294 | 
  295 |     await loginSupplier(page);
  296 |     await goto(page, '/vehicles');
  297 |     const rows = page.locator('.table tbody tr');
  298 | 
  299 |     await page.locator('.vehicles__search').fill(brand);
  300 |     await page.waitForTimeout(400);
  301 |     await expect(
  302 |       rows,
  303 |       `поле обіцяє «Пошук за держномером або маркою», марка авто — «${brand}»; ` +
  304 |         'пошук за нею має знаходити авто',
  305 |     ).toHaveCount(1);
  306 |   });
  307 | });
  308 | 
  309 | test.describe('S-10 Водії', () => {
  310 |   test('S-10.1 X-01 у таблиці всі водії з API', async ({ page }) => {
  311 |     await loginSupplier(page);
  312 |     await goto(page, '/drivers');
  313 | 
  314 |     const drivers = await api.drivers();
  315 |     const rows = page.locator('.table tbody tr');
```