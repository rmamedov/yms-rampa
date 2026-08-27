# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 24-supplier-route-sheets.spec.ts >> S-08 Маршрутні листи >> S-08.3 у колонці «Авто» видно держномер саме цього бронювання
- Location: tests/24-supplier-route-sheets.spec.ts:165:7

# Error details

```
Error: у бронюванні авто UT8911XX, а в рядку листа показано «UT1349XX». Користувач має бачити авто свого бронювання, а не чуже.

expect(received).toBe(expected) // Object.is equality

Expected: true
Received: false
```

# Test source

```ts
  87  |     orderId,
  88  |   });
  89  |   createdBookings.push(booking.id);
  90  |   return { bookingId: booking.id, slot: free!, store, plate, orderId };
  91  | }
  92  | 
  93  | test.describe('S-08 Маршрутні листи', () => {
  94  |   test('S-08.1 список листів збігається з даними API', async ({ page }) => {
  95  |     const today = kyivToday();
  96  |     const dates = Array.from({ length: 22 }, (_, i) => shiftDate(today, i - 7));
  97  | 
  98  |     const read = async () => {
  99  |       const out: { date: string; points: number }[] = [];
  100 |       for (const date of dates) {
  101 |         const sheet = await api.sheet(date).catch(() => null);
  102 |         if (sheet && sheet.points.length > 0) {
  103 |           out.push({ date, points: sheet.points.length });
  104 |         }
  105 |       }
  106 |       return out;
  107 |     };
  108 | 
  109 |     const before = await read();
  110 |     await loginSupplier(page);
  111 |     await goto(page, '/route-sheets');
  112 |     await expect(page.locator('.sheets li').first()).toBeVisible();
  113 |     const upcomingHrefs = await page.locator('.sheets a').evaluateAll((els) =>
  114 |       els.map((e) => e.getAttribute('href') ?? ''),
  115 |     );
  116 |     await page.locator('button:has-text("Архів")').click();
  117 |     await page.waitForTimeout(400);
  118 |     const archiveHrefs = await page.locator('.sheets a').evaluateAll((els) =>
  119 |       els.map((e) => e.getAttribute('href') ?? ''),
  120 |     );
  121 |     const after = await read();
  122 | 
  123 |     const { must } = stableSet(before, after, (s) => s.date);
  124 |     const shown = [...upcomingHrefs, ...archiveHrefs].map((h) => h.replace('/route-sheets/', ''));
  125 | 
  126 |     for (const sheet of must) {
  127 |       expect(shown, `лист на ${sheet.date} має бути у списку`).toContain(sheet.date);
  128 |       const tab = sheet.date >= today ? upcomingHrefs : archiveHrefs;
  129 |       expect(
  130 |         tab.map((h) => h.replace('/route-sheets/', '')),
  131 |         `лист на ${sheet.date} має бути у вкладці ${sheet.date >= today ? 'Актуальні' : 'Архів'}`,
  132 |       ).toContain(sheet.date);
  133 |     }
  134 |   });
  135 | 
  136 |   test('S-08.2 деталі листа показують усі точки з правильними полями', async ({ page }) => {
  137 |     const date = workingDay(3);
  138 |     const seeded = await seedBooking({ date });
  139 | 
  140 |     await loginSupplier(page);
  141 |     await goto(page, `/route-sheets/${date}`);
  142 |     await expect(page.locator('.table tbody tr').first()).toBeVisible();
  143 | 
  144 |     const sheet = await api.sheet(date);
  145 |     expect(await page.locator('.table tbody tr').count(), `в API ${sheet.points.length} точок`).toBe(
  146 |       sheet.points.length,
  147 |     );
  148 | 
  149 |     const text = await bodyText(page);
  150 |     for (const point of sheet.points) {
  151 |       expect(text, `час точки ${point.localTime}`).toContain(point.localTime);
  152 |       expect(text, `адреса ${point.address}`).toContain(point.address);
  153 |       expect(text, `магазин ${point.storeName}`).toContain(point.storeName);
  154 |       if (point.orderId) {
  155 |         expect(text, `замовлення ${point.orderId}`).toContain(point.orderId);
  156 |       }
  157 |     }
  158 | 
  159 |     const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
  160 |     await expect(myRow).toBeVisible();
  161 |     await expect(myRow, 'статус нового бронювання').toContainText('Заброньовано');
  162 |     expect(normalizedText(await myRow.innerText()), 'кількість палет').toContain('8');
  163 |   });
  164 | 
  165 |   test('S-08.3 у колонці «Авто» видно держномер саме цього бронювання', async ({ page }) => {
  166 |     const date = workingDay(3);
  167 |     // Держномер, якого свідомо немає в довіднику: у листі має стояти саме він.
  168 |     const plate = uitestPlate(stamp());
  169 |     const seeded = await seedBooking({ date, plate });
  170 | 
  171 |     await loginSupplier(page);
  172 |     await goto(page, `/route-sheets/${date}`);
  173 |     const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
  174 |     await expect(myRow).toBeVisible();
  175 | 
  176 |     const shown = normalizedText(await myRow.innerText());
  177 |     const selected = await myRow
  178 |       .locator('select')
  179 |       .first()
  180 |       .evaluate((el) => (el as HTMLSelectElement).selectedOptions[0]?.text ?? '')
  181 |       .catch(() => '');
  182 | 
  183 |     expect(
  184 |       shown.includes(plate) || selected.includes(plate),
  185 |       `у бронюванні авто ${plate}, а в рядку листа показано «${selected || shown}». ` +
  186 |         'Користувач має бачити авто свого бронювання, а не чуже.',
> 187 |     ).toBe(true);
      |       ^ Error: у бронюванні авто UT8911XX, а в рядку листа показано «UT1349XX». Користувач має бачити авто свого бронювання, а не чуже.
  188 |   });
  189 | 
  190 |   test('S-08.4 призначення водія на весь лист', async ({ page }) => {
  191 |     const date = workingDay(4);
  192 |     const seeded = await seedBooking({ date });
  193 | 
  194 |     await loginSupplier(page);
  195 |     await goto(page, `/route-sheets/${date}`);
  196 |     await expect(page.locator('#sheet-driver')).toBeVisible();
  197 | 
  198 |     const label = `${driver.lastName} ${driver.firstName} · ${driver.phone}`;
  199 |     const options = await page.locator('#sheet-driver option').allInnerTexts();
  200 |     expect(options.map(normalizedText), 'усі активні водії мають бути у переліку').toContain(label);
  201 | 
  202 |     await Promise.all([
  203 |       page.waitForResponse((r) => r.url().includes('/route-sheets/driver') && r.request().method() === 'POST'),
  204 |       page.locator('#sheet-driver').selectOption({ label }),
  205 |     ]);
  206 |     await expect(toast(page, 'Водія призначено')).toBeVisible();
  207 | 
  208 |     const sheet = await api.sheet(date);
  209 |     const point = sheet.points.find((p) => p.bookingId === seeded.bookingId);
  210 |     expect(point?.driverId, 'водій має бути записаний у бронювання').toBe(driver.id);
  211 |     await expect(page.locator('.table tbody tr').filter({ hasText: seeded.orderId })).toContainText(
  212 |       driver.lastName,
  213 |     );
  214 | 
  215 |     await expect(
  216 |       page.locator('.sheet-driver'),
  217 |       'кабінет має пояснювати, що зняти водія з усього листа не можна',
  218 |     ).toContainText('Зняти водія з усього листа не можна');
  219 |   });
  220 | 
  221 |   test('S-08.5 призначення і зняття водія в окремій точці', async ({ page }) => {
  222 |     const date = workingDay(4);
  223 |     const seeded = await seedBooking({ date });
  224 | 
  225 |     await loginSupplier(page);
  226 |     await goto(page, `/route-sheets/${date}`);
  227 |     const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
  228 |     await expect(myRow).toBeVisible();
  229 | 
  230 |     const driverSelect = myRow.locator('select').last();
  231 |     await Promise.all([
  232 |       page.waitForResponse((r) => r.url().includes('/route-sheets/driver')),
  233 |       driverSelect.selectOption({ label: `${driver.lastName} ${driver.firstName}` }),
  234 |     ]);
  235 |     await page.waitForTimeout(800);
  236 |     expect(
  237 |       (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId,
  238 |       'водія призначено на точку',
  239 |     ).toBe(driver.id);
  240 | 
  241 |     // Зняття водія з точки — порожній варіант у списку.
  242 |     await Promise.all([
  243 |       page.waitForResponse((r) => r.url().includes('/route-sheets/driver')),
  244 |       page.locator('.table tbody tr').filter({ hasText: seeded.orderId }).locator('select').last().selectOption(''),
  245 |     ]);
  246 |     await page.waitForTimeout(800);
  247 |     expect(
  248 |       (await api.sheet(date)).points.find((p) => p.bookingId === seeded.bookingId)?.driverId,
  249 |       'водія знято з точки',
  250 |     ).toBeNull();
  251 |   });
  252 | 
  253 |   test('S-08.6 перенесення бронювання на інший слот', async ({ page }) => {
  254 |     test.setTimeout(150_000);
  255 |     const date = workingDay(3);
  256 |     // Авто з довідника: перенесення підставляє його у форму за держномером.
  257 |     const plate = uitestPlate(stamp());
  258 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3, brand: 'UITEST перенесення' });
  259 |     createdVehicles.push(vehicle.id);
  260 |     const seeded = await seedBooking({ date, storeIndex: 2, plate });
  261 | 
  262 |     await loginSupplier(page);
  263 |     await goto(page, `/route-sheets/${date}`);
  264 |     const myRow = page.locator('.table tbody tr').filter({ hasText: seeded.orderId });
  265 |     await expect(myRow).toBeVisible();
  266 |     await myRow.locator('button:has-text("Перенести")').click();
  267 | 
  268 |     await page.waitForURL(new RegExp(`/booking/stores/${seeded.store.storeId}`), { timeout: 20_000 });
  269 |     await expect(page.locator('.transfer-banner'), 'має бути банер перенесення').toContainText(
  270 |       'Перенесення бронювання',
  271 |     );
  272 | 
  273 |     await selectGridDate(page, date);
  274 |     const grid = await api.slots(seeded.store.storeId, date);
  275 |     const target = grid.slots
  276 |       .filter((s) => s.selectable && s.slotStart !== seeded.slot.slotStart)
  277 |       .pop();
  278 |     expect(target, 'потрібен інший вільний слот').toBeTruthy();
  279 | 
  280 |     const column = seeded.store.ramps.findIndex((r) => r.rampId === target!.rampId);
  281 |     const cell = cellAt(page, `${target!.localStart}`, column);
  282 |     await Promise.all([
  283 |       page.waitForResponse((r) => r.url().includes('/slots/hold') && r.request().method() === 'POST'),
  284 |       cell.locator('button.slot').click(),
  285 |     ]);
  286 | 
  287 |     // Значення полів — це input.value, а не текст панелі.
```