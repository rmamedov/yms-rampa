# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 30-store.spec.ts >> M-04 Позапланове прибуття >> M-04.1 X-01 список постачальників у формі повний
- Location: tests/30-store.spec.ts:825:7

# Error details

```
Error: у списку 40 постачальників, а в системі 44; немає: UITEST-Постачальник-mtc2alvh45-фільтр, UITEST-Постачальник-mtc3355n104-фільтр, UITEST-Постачальник-mtc5q7fc106-фільтр, UITEST-Постачальник-mtc6e04n894-фільтр

expect(received).toHaveLength(expected)

Expected length: 0
Received length: 4
Received array:  ["UITEST-Постачальник-mtc2alvh45-фільтр", "UITEST-Постачальник-mtc3355n104-фільтр", "UITEST-Постачальник-mtc5q7fc106-фільтр", "UITEST-Постачальник-mtc6e04n894-фільтр"]
```

# Test source

```ts
  739 |     expect(after.delayed.flag, 'бекенд має зберегти прапорець затримки').toBe(true);
  740 |     expect(after.delayed.reason).toBe('затори');
  741 |   });
  742 | 
  743 |   test('M-03.8 переведення на іншу рампу', async ({ page }) => {
  744 |     test.setTimeout(120_000);
  745 |     const multiRamp = stores.filter((s) => s.ramps.filter((r) => r.active !== false).length > 1);
  746 |     expect(multiRamp.length, 'для переведення потрібна філія з 2+ рампами').toBeGreaterThan(0);
  747 |     const target = multiRamp[0];
  748 | 
  749 |     // Слот обирається свідомо: переводити є куди лише тоді, коли в ТОЙ САМИЙ
  750 |     // час вільна ще одна рампа. Без цього бронювання лягало на «перший вільний»
  751 |     // слот, попередні перевірки встигали зайняти сусідні рампи того ж часу,
  752 |     // і кнопка переведення чесно вимикалася — тест падав не на дефекті.
  753 |     const grid = await slotGrid(ctx, supplierToken, target.storeId, kyivDateKey());
  754 |     const freePerStart = new Map<string, number>();
  755 |     for (const slot of grid.slots) {
  756 |       if (slot.state === 'available' && slot.selectable) {
  757 |         freePerStart.set(slot.slotStart, (freePerStart.get(slot.slotStart) ?? 0) + 1);
  758 |       }
  759 |     }
  760 |     const pairSlot = [...freePerStart.entries()]
  761 |       .filter(([, count]) => count >= 2)
  762 |       .map(([slotStart]) => slotStart)
  763 |       .sort()[0];
  764 |     expect(
  765 |       pairSlot,
  766 |       'для переведення потрібен час, у який на філії вільні щонайменше дві рампи',
  767 |     ).toBeTruthy();
  768 | 
  769 |     const booking = await createBooking(ctx, supplierToken, [target], {
  770 |       label: 'reassign',
  771 |       slotStart: pairSlot,
  772 |     });
  773 |     created.push(booking.id);
  774 | 
  775 |     await openBoard(page, target);
  776 |     const plate = booking.vehicle.plateNumber;
  777 |     await cardByPlate(page, plate).getByRole('button', { name: 'Перевести на іншу рампу' }).click();
  778 | 
  779 |     const dialog = page.locator('[role=dialog]');
  780 |     await expect(dialog).toBeVisible();
  781 |     const currentName = target.ramps.find((r) => r.rampId === booking.rampId)?.name as string;
  782 |     await expect(dialog, 'у вікні видно поточну рампу').toContainText(currentName);
  783 | 
  784 |     const options = await optionTexts(page, '#target-ramp');
  785 |     expect(options.length, 'має бути хоча б одна вільна рампа для переведення').toBeGreaterThan(0);
  786 |     const newRampName = options[0];
  787 |     await dialog.locator('#target-ramp').selectOption({ label: newRampName });
  788 |     await dialog.getByRole('button', { name: 'Підтвердити' }).click();
  789 |     await page.waitForTimeout(1500);
  790 | 
  791 |     await reloadBoard(page);
  792 |     const column = page.locator('.board__col').filter({ hasText: plate }).first();
  793 |     await expect(
  794 |       column.locator('.board__colhead'),
  795 |       'після перезавантаження картка має стояти в колонці нової рампи',
  796 |     ).toContainText(newRampName);
  797 | 
  798 |     const after = await readBooking(ctx, supplierToken, booking.id);
  799 |     const newRampId = target.ramps.find((r) => r.name === newRampName)?.rampId;
  800 |     expect(after.rampId, 'бекенд має бачити нову рампу').toBe(newRampId);
  801 |   });
  802 | });
  803 | 
  804 | // ===========================================================================
  805 | // M-04. Walk-in (позапланове прибуття)
  806 | // ===========================================================================
  807 | 
  808 | test.describe('M-04 Позапланове прибуття', () => {
  809 |   /** Скільки постачальників справді є в системі (ґрунт для перевірки повноти). */
  810 |   async function allSuppliers(): Promise<{ id: string; name: string }[]> {
  811 |     const login = await ctx.post(`${HOSTS.admin}/api/admin/v1/auth/login`, { data: CREDS.admin });
  812 |     const token = (await login.json()).accessToken as string;
  813 |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/suppliers?perPage=100`, {
  814 |       headers: { Authorization: `Bearer ${token}` },
  815 |     });
  816 |     const body = await res.json();
  817 |     const items = body.items as { id: string; name: string; status?: string }[];
  818 |     expect(
  819 |       items.length,
  820 |       `сторінка повернула ${items.length} із ${body.total} — довідник для звірки неповний`,
  821 |     ).toBe(body.total as number);
  822 |     return items.filter((s) => s.status !== 'archived');
  823 |   }
  824 | 
  825 |   test('M-04.1 X-01 список постачальників у формі повний', async ({ page }) => {
  826 |     const expected = await allSuppliers();
  827 |     expect(expected.length, 'для перевірки потрібен хоч один постачальник').toBeGreaterThan(0);
  828 | 
  829 |     await openBoard(page, primaryStore());
  830 |     await openWalkIn(page);
  831 | 
  832 |     const shown = await optionTexts(page, '#wi-supplier');
  833 |     const missing = expected.filter((s) => !shown.some((t) => t.includes(s.name)));
  834 |     expect(
  835 |       missing.map((s) => s.name),
  836 |       `у списку ${shown.length} постачальників, а в системі ${expected.length}; немає: ${missing
  837 |         .map((s) => s.name)
  838 |         .join(', ')}`,
> 839 |     ).toHaveLength(0);
      |       ^ Error: у списку 40 постачальників, а в системі 44; немає: UITEST-Постачальник-mtc2alvh45-фільтр, UITEST-Постачальник-mtc3355n104-фільтр, UITEST-Постачальник-mtc5q7fc106-фільтр, UITEST-Постачальник-mtc6e04n894-фільтр
  840 |   });
  841 | 
  842 |   test('M-04.2 X-05 валідація полів форми', async ({ page }) => {
  843 |     const store = primaryStore();
  844 |     await openBoard(page, store);
  845 |     const dialog = await openWalkIn(page);
  846 | 
  847 |     await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
  848 |     await expect(
  849 |       dialog.locator('.form-error').first(),
  850 |       'порожня форма має пояснити, чого саме бракує',
  851 |     ).toBeVisible();
  852 |     const errors = (await dialog.locator('.form-error').allInnerTexts()).join(' | ');
  853 |     expect(errors, 'постачальник').toContain('Оберіть постачальника або вкажіть назву');
  854 |     expect(errors, 'номер авто').toContain('Вкажіть номер авто');
  855 |     expect(errors, 'тоннаж').toContain('Вкажіть тоннаж авто');
  856 |     expect(errors, 'палети').toContain('Кількість палет — від 1 до 33');
  857 |     expect(errors, 'слот').toContain('Оберіть вільний слот');
  858 | 
  859 |     // Межові значення: 0 і 34 палети мають відхилятися.
  860 |     await dialog.locator('#wi-pallets').fill('34');
  861 |     await dialog.locator('#wi-pallets').dispatchEvent('input');
  862 |     await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
  863 |     expect(
  864 |       (await dialog.locator('.form-error').allInnerTexts()).join(' '),
  865 |       '34 палети мають відхилятися',
  866 |     ).toContain('Кількість палет — від 1 до 33');
  867 | 
  868 |     // Тоннаж понад ліміт філії має відхилятися з окремим текстом. Ліміт — саме
  869 |     // тієї філії, яку показує дошка: форма перевіряє її, а не довільну філію
  870 |     // пісочниці (у 2227 ліміт 10 т, у 2229 — 40, і «10 + 5» тут ні про що).
  871 |     const limit = store.maxVehicleWeightTons;
  872 |     await dialog.locator('#wi-weight').fill(String(limit + 5));
  873 |     await dialog.locator('#wi-weight').dispatchEvent('input');
  874 |     await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
  875 |     expect(
  876 |       (await dialog.locator('.form-error').allInnerTexts()).join(' '),
  877 |       'тоннаж понад ліміт філії',
  878 |     ).toContain('Тоннаж авто перевищує допустимий');
  879 |   });
  880 | 
  881 |   test('M-04.3 реєстрація постачальника зі списку → статус «на місці»', async ({ page }) => {
  882 |     test.setTimeout(120_000);
  883 |     await openBoard(page, primaryStore());
  884 |     const dialog = await openWalkIn(page);
  885 | 
  886 |     const suppliers = await optionTexts(page, '#wi-supplier');
  887 |     expect(suppliers.length, 'у формі має бути з чого вибрати').toBeGreaterThan(0);
  888 |     const slots = await optionTexts(page, '#wi-slot');
  889 |     expect(slots.length, 'мають бути вільні слоти на сьогодні').toBeGreaterThan(0);
  890 | 
  891 |     const plate = testPlate();
  892 |     await dialog.locator('#wi-supplier').selectOption({ label: suppliers[0] });
  893 |     await dialog.locator('#wi-plate').fill(plate);
  894 |     await dialog.locator('#wi-weight').fill('8');
  895 |     await dialog.locator('#wi-weight').dispatchEvent('input');
  896 |     await dialog.locator('#wi-pallets').fill('6');
  897 |     await dialog.locator('#wi-pallets').dispatchEvent('input');
  898 |     await dialog.locator('#wi-order').fill('UITEST-walkin');
  899 |     await dialog.locator('#wi-slot').selectOption({ label: slots[0] });
  900 | 
  901 |     const [response] = await Promise.all([
  902 |       page.waitForResponse((r) => r.url().includes('/bookings/walk-in')),
  903 |       dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click(),
  904 |     ]);
  905 |     expect(response.status(), 'реєстрація має завершитися створенням').toBe(201);
  906 |     const body = (await response.json()) as Booking;
  907 |     created.push(body.id);
  908 |     registerArtifact('walk-in', body.id, `${plate} · UITEST-walkin`);
  909 | 
  910 |     await reloadBoard(page);
  911 |     const card = cardByPlate(page, plate);
  912 |     await expect(card, 'позапланове прибуття має зʼявитися на дошці').toBeVisible();
  913 |     await expect(card.locator('.badge').first(), 'статус «на місці»').toContainText(
  914 |       'Очікує на території',
  915 |     );
  916 |     await expect(card, 'картка має бути позначена як позапланова').toContainText('Позапланове');
  917 |   });
  918 | 
  919 |   test('M-04.4 реєстрація постачальника «поза системою»', async ({ page }) => {
  920 |     test.setTimeout(120_000);
  921 |     await openBoard(page, primaryStore());
  922 |     const dialog = await openWalkIn(page);
  923 | 
  924 |     await dialog.getByRole('button', { name: 'Поза системою' }).click();
  925 |     await expect(dialog.locator('#wi-external'), 'має зʼявитися поле назви').toBeVisible();
  926 | 
  927 |     const plate = testPlate();
  928 |     const name = 'UITEST-Поза системою';
  929 |     await dialog.locator('#wi-external').fill(name);
  930 |     await dialog.locator('#wi-plate').fill(plate);
  931 |     await dialog.locator('#wi-weight').fill('7');
  932 |     await dialog.locator('#wi-weight').dispatchEvent('input');
  933 |     await dialog.locator('#wi-pallets').fill('3');
  934 |     await dialog.locator('#wi-pallets').dispatchEvent('input');
  935 |     const slots = await optionTexts(page, '#wi-slot');
  936 |     await dialog.locator('#wi-slot').selectOption({ label: slots[0] });
  937 | 
  938 |     const [response] = await Promise.all([
  939 |       page.waitForResponse((r) => r.url().includes('/bookings/walk-in')),
```