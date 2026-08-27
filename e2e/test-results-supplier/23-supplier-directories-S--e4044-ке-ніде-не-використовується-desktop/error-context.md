# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 23-supplier-directories.spec.ts >> S-09 Довідник авто >> S-09.7 видалення авто, яке ніде не використовується
- Location: tests/23-supplier-directories.spec.ts:201:7

# Error details

```
Error: авто без жодного бронювання має видалятись; сервер відповів 409: {"type":"about:blank","title":"\u041a\u043e\u043d\u0444\u043b\u0456\u043a\u0442 \u0441\u0442\u0430\u043d\u0443","status":409,"detail":"\u0410\u0432\u0442\u043e \u043f\u0440\u0438\u0432\u0027\u044f\u04

expect(received).toBe(expected) // Object.is equality

Expected: 204
Received: 409
```

# Test source

```ts
  120 | 
  121 |     const save = page.locator('.modal__window button:has-text("Зберегти")');
  122 |     await expect(save, 'порожня форма не зберігається').toBeDisabled();
  123 |     await expect(page.locator('.modal__window')).toContainText('Вкажіть держномер');
  124 | 
  125 |     await saveVehicleForm(page, { plate: 'AB1', weight: '5' });
  126 |     await expect(page.locator('.modal__window')).toContainText('Держномер має містити від 4 до 12 символів');
  127 |     await expect(save).toBeDisabled();
  128 | 
  129 |     await saveVehicleForm(page, { plate: uitestPlate(stamp()), weight: '' });
  130 |     await expect(page.locator('.modal__window')).toContainText('Вкажіть вантажопідйомність');
  131 |     await expect(save).toBeDisabled();
  132 | 
  133 |     await saveVehicleForm(page, { weight: '0' });
  134 |     await expect(page.locator('.modal__window')).toContainText('Вантажопідйомність має бути більшою за 0');
  135 |     await expect(save).toBeDisabled();
  136 | 
  137 |     await saveVehicleForm(page, { weight: '3' });
  138 |     await expect(save, 'коректні дані — можна зберігати').toBeEnabled();
  139 |   });
  140 | 
  141 |   test('S-09.4 дублікат держномера в межах постачальника відхиляється', async ({ page }) => {
  142 |     const plate = uitestPlate(stamp());
  143 |     const existing = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  144 |     createdVehicles.push(existing.id);
  145 | 
  146 |     await loginSupplier(page);
  147 |     await goto(page, '/vehicles');
  148 |     await page.locator('button:has-text("Додати авто")').click();
  149 |     await saveVehicleForm(page, { plate, weight: '4' });
  150 | 
  151 |     await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  152 |     await expect(page.locator('.modal__window button:has-text("Зберегти")')).toBeDisabled();
  153 | 
  154 |     // Той самий номер у нижньому регістрі — теж дублікат.
  155 |     await saveVehicleForm(page, { plate: plate.toLowerCase() });
  156 |     await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  157 |   });
  158 | 
  159 |   test('S-09.5 редагування зберігає нові марку і вантажопідйомність', async ({ page }) => {
  160 |     const plate = uitestPlate(stamp());
  161 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand: 'UITEST старий' });
  162 |     createdVehicles.push(vehicle.id);
  163 | 
  164 |     await loginSupplier(page);
  165 |     await goto(page, '/vehicles');
  166 |     await row(page, plate).locator('button:has-text("Редагувати")').click();
  167 |     await expect(page.locator('.modal__title')).toHaveText('Редагування авто');
  168 |     expect(await page.locator('#veh-plate').inputValue(), 'форма має відкритись із поточними даними').toBe(plate);
  169 | 
  170 |     await saveVehicleForm(page, { brand: 'UITEST новий', weight: '18.5' });
  171 |     await Promise.all([
  172 |       page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'PATCH'),
  173 |       page.locator('.modal__window button:has-text("Зберегти")').click(),
  174 |     ]);
  175 | 
  176 |     await expect(row(page, plate)).toContainText('UITEST новий');
  177 |     const updated = (await api.vehicles()).find((v) => v.id === vehicle.id);
  178 |     expect(updated?.brand).toBe('UITEST новий');
  179 |     expect(updated?.weightTons).toBe(18.5);
  180 |   });
  181 | 
  182 |   test('S-09.6 деактивація і повернення авто в роботу', async ({ page }) => {
  183 |     const plate = uitestPlate(stamp());
  184 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  185 |     createdVehicles.push(vehicle.id);
  186 | 
  187 |     await loginSupplier(page);
  188 |     await goto(page, '/vehicles');
  189 | 
  190 |     await row(page, plate).locator('button:has-text("Деактивувати")').click();
  191 |     await expect(page.locator('.toast__text').first()).toHaveText('Авто деактивовано');
  192 |     await expect(row(page, plate)).toContainText('Деактивоване');
  193 |     expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(false);
  194 | 
  195 |     await row(page, plate).locator('button:has-text("Активувати")').click();
  196 |     await page.waitForTimeout(800);
  197 |     await expect(row(page, plate)).toContainText('Активне');
  198 |     expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(true);
  199 |   });
  200 | 
  201 |   test('S-09.7 видалення авто, яке ніде не використовується', async ({ page }) => {
  202 |     const plate = uitestPlate(stamp());
  203 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  204 |     createdVehicles.push(vehicle.id);
  205 | 
  206 |     await loginSupplier(page);
  207 |     await goto(page, '/vehicles');
  208 |     await row(page, plate).locator('button:has-text("Видалити")').click();
  209 |     await expect(page.locator('.modal__window')).toContainText(`Видалити авто ${plate}?`);
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
> 220 |     ).toBe(204);
      |       ^ Error: авто без жодного бронювання має видалятись; сервер відповів 409: {"type":"about:blank","title":"\u041a\u043e\u043d\u0444\u043b\u0456\u043a\u0442 \u0441\u0442\u0430\u043d\u0443","status":409,"detail":"\u0410\u0432\u0442\u043e \u043f\u0440\u0438\u0432\u0027\u044f\u04
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
```