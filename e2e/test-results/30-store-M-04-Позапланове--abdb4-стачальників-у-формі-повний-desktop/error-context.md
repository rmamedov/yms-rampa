# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 30-store.spec.ts >> M-04 Позапланове прибуття >> M-04.1 X-01 список постачальників у формі повний
- Location: tests/30-store.spec.ts:789:7

# Error details

```
Error: у списку 31 постачальників, а в системі 34; немає: UITEST-Постачальник-mtc2alvh45-фільтр, UITEST-Постачальник-mtc3355n104-фільтр, UITEST-Постачальник-mtc5q7fc106-фільтр

expect(received).toHaveLength(expected)

Expected length: 0
Received length: 3
Received array:  ["UITEST-Постачальник-mtc2alvh45-фільтр", "UITEST-Постачальник-mtc3355n104-фільтр", "UITEST-Постачальник-mtc5q7fc106-фільтр"]
```

# Test source

```ts
  703 |     await expect(dialog).toBeVisible();
  704 |     expect(await optionTexts(page, '#delay-reason'), 'довідник причин затримки').toEqual([
  705 |       ...DELAY_REASONS,
  706 |     ]);
  707 | 
  708 |     // X-05: без причини і часу форма не проходить.
  709 |     await dialog.getByRole('button', { name: 'Зберегти' }).click();
  710 |     const errors = (await dialog.locator('.form-error').allInnerTexts()).join(' ');
  711 |     expect(errors, 'мають бути вимоги причини і нового часу').toContain('Оберіть причину затримки');
  712 |     expect(errors).toContain('Вкажіть новий орієнтовний час');
  713 | 
  714 |     // Новий час — на годину пізніше за початок слоту, у межах тієї самої доби.
  715 |     const eta = new Date(new Date(booking.slotStart).getTime() + 60 * 60_000).toISOString();
  716 |     await dialog.locator('#delay-reason').selectOption({ label: 'затори' });
  717 |     await dialog.locator('#delay-eta').fill(kyivTime(eta));
  718 |     await dialog.getByRole('button', { name: 'Зберегти' }).click();
  719 |     await page.waitForTimeout(1500);
  720 | 
  721 |     await reloadBoard(page);
  722 |     const card = cardByPlate(page, plate);
  723 |     await expect(card, 'на картці має бути позначка затримки').toContainText('Затримка до');
  724 |     const after = (await readBooking(ctx, supplierToken, booking.id)) as unknown as {
  725 |       delayed: { flag: boolean; reason: string | null };
  726 |     };
  727 |     expect(after.delayed.flag, 'бекенд має зберегти прапорець затримки').toBe(true);
  728 |     expect(after.delayed.reason).toBe('затори');
  729 |   });
  730 | 
  731 |   test('M-03.8 переведення на іншу рампу', async ({ page }) => {
  732 |     test.setTimeout(120_000);
  733 |     const multiRamp = stores.filter((s) => s.ramps.filter((r) => r.active !== false).length > 1);
  734 |     expect(multiRamp.length, 'для переведення потрібна філія з 2+ рампами').toBeGreaterThan(0);
  735 |     const target = multiRamp[0];
  736 |     const booking = await createBooking(ctx, supplierToken, [target], { label: 'reassign' });
  737 |     created.push(booking.id);
  738 | 
  739 |     await openBoard(page, target);
  740 |     const plate = booking.vehicle.plateNumber;
  741 |     await cardByPlate(page, plate).getByRole('button', { name: 'Перевести на іншу рампу' }).click();
  742 | 
  743 |     const dialog = page.locator('[role=dialog]');
  744 |     await expect(dialog).toBeVisible();
  745 |     const currentName = target.ramps.find((r) => r.rampId === booking.rampId)?.name as string;
  746 |     await expect(dialog, 'у вікні видно поточну рампу').toContainText(currentName);
  747 | 
  748 |     const options = await optionTexts(page, '#target-ramp');
  749 |     expect(options.length, 'має бути хоча б одна вільна рампа для переведення').toBeGreaterThan(0);
  750 |     const newRampName = options[0];
  751 |     await dialog.locator('#target-ramp').selectOption({ label: newRampName });
  752 |     await dialog.getByRole('button', { name: 'Підтвердити' }).click();
  753 |     await page.waitForTimeout(1500);
  754 | 
  755 |     await reloadBoard(page);
  756 |     const column = page.locator('.board__col').filter({ hasText: plate }).first();
  757 |     await expect(
  758 |       column.locator('.board__colhead'),
  759 |       'після перезавантаження картка має стояти в колонці нової рампи',
  760 |     ).toContainText(newRampName);
  761 | 
  762 |     const after = await readBooking(ctx, supplierToken, booking.id);
  763 |     const newRampId = target.ramps.find((r) => r.name === newRampName)?.rampId;
  764 |     expect(after.rampId, 'бекенд має бачити нову рампу').toBe(newRampId);
  765 |   });
  766 | });
  767 | 
  768 | // ===========================================================================
  769 | // M-04. Walk-in (позапланове прибуття)
  770 | // ===========================================================================
  771 | 
  772 | test.describe('M-04 Позапланове прибуття', () => {
  773 |   /** Скільки постачальників справді є в системі (ґрунт для перевірки повноти). */
  774 |   async function allSuppliers(): Promise<{ id: string; name: string }[]> {
  775 |     const login = await ctx.post(`${HOSTS.admin}/api/admin/v1/auth/login`, { data: CREDS.admin });
  776 |     const token = (await login.json()).accessToken as string;
  777 |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/suppliers?perPage=100`, {
  778 |       headers: { Authorization: `Bearer ${token}` },
  779 |     });
  780 |     const body = await res.json();
  781 |     const items = body.items as { id: string; name: string; status?: string }[];
  782 |     expect(
  783 |       items.length,
  784 |       `сторінка повернула ${items.length} із ${body.total} — довідник для звірки неповний`,
  785 |     ).toBe(body.total as number);
  786 |     return items.filter((s) => s.status !== 'archived');
  787 |   }
  788 | 
  789 |   test('M-04.1 X-01 список постачальників у формі повний', async ({ page }) => {
  790 |     const expected = await allSuppliers();
  791 |     expect(expected.length, 'для перевірки потрібен хоч один постачальник').toBeGreaterThan(0);
  792 | 
  793 |     await openBoard(page, primaryStore());
  794 |     await openWalkIn(page);
  795 | 
  796 |     const shown = await optionTexts(page, '#wi-supplier');
  797 |     const missing = expected.filter((s) => !shown.some((t) => t.includes(s.name)));
  798 |     expect(
  799 |       missing.map((s) => s.name),
  800 |       `у списку ${shown.length} постачальників, а в системі ${expected.length}; немає: ${missing
  801 |         .map((s) => s.name)
  802 |         .join(', ')}`,
> 803 |     ).toHaveLength(0);
      |       ^ Error: у списку 31 постачальників, а в системі 34; немає: UITEST-Постачальник-mtc2alvh45-фільтр, UITEST-Постачальник-mtc3355n104-фільтр, UITEST-Постачальник-mtc5q7fc106-фільтр
  804 |   });
  805 | 
  806 |   test('M-04.2 X-05 валідація полів форми', async ({ page }) => {
  807 |     const store = primaryStore();
  808 |     await openBoard(page, store);
  809 |     const dialog = await openWalkIn(page);
  810 | 
  811 |     await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
  812 |     await expect(
  813 |       dialog.locator('.form-error').first(),
  814 |       'порожня форма має пояснити, чого саме бракує',
  815 |     ).toBeVisible();
  816 |     const errors = (await dialog.locator('.form-error').allInnerTexts()).join(' | ');
  817 |     expect(errors, 'постачальник').toContain('Оберіть постачальника або вкажіть назву');
  818 |     expect(errors, 'номер авто').toContain('Вкажіть номер авто');
  819 |     expect(errors, 'тоннаж').toContain('Вкажіть тоннаж авто');
  820 |     expect(errors, 'палети').toContain('Кількість палет — від 1 до 33');
  821 |     expect(errors, 'слот').toContain('Оберіть вільний слот');
  822 | 
  823 |     // Межові значення: 0 і 34 палети мають відхилятися.
  824 |     await dialog.locator('#wi-pallets').fill('34');
  825 |     await dialog.locator('#wi-pallets').dispatchEvent('input');
  826 |     await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
  827 |     expect(
  828 |       (await dialog.locator('.form-error').allInnerTexts()).join(' '),
  829 |       '34 палети мають відхилятися',
  830 |     ).toContain('Кількість палет — від 1 до 33');
  831 | 
  832 |     // Тоннаж понад ліміт філії має відхилятися з окремим текстом. Ліміт — саме
  833 |     // тієї філії, яку показує дошка: форма перевіряє її, а не довільну філію
  834 |     // пісочниці (у 2227 ліміт 10 т, у 2229 — 40, і «10 + 5» тут ні про що).
  835 |     const limit = store.maxVehicleWeightTons;
  836 |     await dialog.locator('#wi-weight').fill(String(limit + 5));
  837 |     await dialog.locator('#wi-weight').dispatchEvent('input');
  838 |     await dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click();
  839 |     expect(
  840 |       (await dialog.locator('.form-error').allInnerTexts()).join(' '),
  841 |       'тоннаж понад ліміт філії',
  842 |     ).toContain('Тоннаж авто перевищує допустимий');
  843 |   });
  844 | 
  845 |   test('M-04.3 реєстрація постачальника зі списку → статус «на місці»', async ({ page }) => {
  846 |     test.setTimeout(120_000);
  847 |     await openBoard(page, primaryStore());
  848 |     const dialog = await openWalkIn(page);
  849 | 
  850 |     const suppliers = await optionTexts(page, '#wi-supplier');
  851 |     expect(suppliers.length, 'у формі має бути з чого вибрати').toBeGreaterThan(0);
  852 |     const slots = await optionTexts(page, '#wi-slot');
  853 |     expect(slots.length, 'мають бути вільні слоти на сьогодні').toBeGreaterThan(0);
  854 | 
  855 |     const plate = `UT${String(Math.floor(1000 + Math.random() * 9000))}XX`;
  856 |     await dialog.locator('#wi-supplier').selectOption({ label: suppliers[0] });
  857 |     await dialog.locator('#wi-plate').fill(plate);
  858 |     await dialog.locator('#wi-weight').fill('8');
  859 |     await dialog.locator('#wi-weight').dispatchEvent('input');
  860 |     await dialog.locator('#wi-pallets').fill('6');
  861 |     await dialog.locator('#wi-pallets').dispatchEvent('input');
  862 |     await dialog.locator('#wi-order').fill('UITEST-walkin');
  863 |     await dialog.locator('#wi-slot').selectOption({ label: slots[0] });
  864 | 
  865 |     const [response] = await Promise.all([
  866 |       page.waitForResponse((r) => r.url().includes('/bookings/walk-in')),
  867 |       dialog.getByRole('button', { name: 'Зареєструвати прибуття' }).click(),
  868 |     ]);
  869 |     expect(response.status(), 'реєстрація має завершитися створенням').toBe(201);
  870 |     const body = (await response.json()) as Booking;
  871 |     created.push(body.id);
  872 |     registerArtifact('walk-in', body.id, `${plate} · UITEST-walkin`);
  873 | 
  874 |     await reloadBoard(page);
  875 |     const card = cardByPlate(page, plate);
  876 |     await expect(card, 'позапланове прибуття має зʼявитися на дошці').toBeVisible();
  877 |     await expect(card.locator('.badge').first(), 'статус «на місці»').toContainText(
  878 |       'Очікує на території',
  879 |     );
  880 |     await expect(card, 'картка має бути позначена як позапланова').toContainText('Позапланове');
  881 |   });
  882 | 
  883 |   test('M-04.4 реєстрація постачальника «поза системою»', async ({ page }) => {
  884 |     test.setTimeout(120_000);
  885 |     await openBoard(page, primaryStore());
  886 |     const dialog = await openWalkIn(page);
  887 | 
  888 |     await dialog.getByRole('button', { name: 'Поза системою' }).click();
  889 |     await expect(dialog.locator('#wi-external'), 'має зʼявитися поле назви').toBeVisible();
  890 | 
  891 |     const plate = `UT${String(Math.floor(1000 + Math.random() * 9000))}XX`;
  892 |     const name = 'UITEST-Поза системою';
  893 |     await dialog.locator('#wi-external').fill(name);
  894 |     await dialog.locator('#wi-plate').fill(plate);
  895 |     await dialog.locator('#wi-weight').fill('7');
  896 |     await dialog.locator('#wi-weight').dispatchEvent('input');
  897 |     await dialog.locator('#wi-pallets').fill('3');
  898 |     await dialog.locator('#wi-pallets').dispatchEvent('input');
  899 |     const slots = await optionTexts(page, '#wi-slot');
  900 |     await dialog.locator('#wi-slot').selectOption({ label: slots[0] });
  901 | 
  902 |     const [response] = await Promise.all([
  903 |       page.waitForResponse((r) => r.url().includes('/bookings/walk-in')),
```