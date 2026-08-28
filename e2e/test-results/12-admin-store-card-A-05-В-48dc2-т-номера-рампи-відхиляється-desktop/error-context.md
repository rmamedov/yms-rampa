# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 12-admin-store-card.spec.ts >> A-05 Вкладка «Слоти» >> A-05.7 дублікат номера рампи відхиляється
- Location: tests/12-admin-store-card.spec.ts:605:7

# Error details

```
Error: expect(locator).toContainText(expected) failed

Locator: locator('.card').filter({ hasText: 'Рампи' }).locator('.field-error')
Expected substring: "Номер рампи — ціле число ≥ 1, унікальне в межах магазину"
Error: strict mode violation: locator('.card').filter({ hasText: 'Рампи' }).locator('.field-error') resolved to 2 elements:
    1) <div class="field-error">Номер рампи — ціле число ≥ 1, унікальне в межах м…</div> aka locator('app-store-slots-tab').getByText('Номер рампи — ціле число ≥ 1')
    2) <div class="field-error">Номер рампи — ціле число ≥ 1, унікальне в межах м…</div> aka getByText('Номер рампи — ціле число ≥ 1').nth(1)

Call log:
  - Expect "toContainText" with timeout 15000ms
  - waiting for locator('.card').filter({ hasText: 'Рампи' }).locator('.field-error')

```

# Page snapshot

```yaml
- generic [ref=f1e4]:
  - complementary [ref=f1e5]:
    - generic [ref=f1e6]: YMS «Рампа»
    - navigation [ref=f1e7]:
      - link "Магазини" [ref=f1e8] [cursor=pointer]:
        - /url: /stores
      - link "Постачальники" [ref=f1e9] [cursor=pointer]:
        - /url: /suppliers
      - link "Користувачі" [ref=f1e10] [cursor=pointer]:
        - /url: /users
      - link "Синхронізація MCP" [ref=f1e11] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=f1e12] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=f1e13]:
      - generic [ref=f1e14]: Адміністратор мережі
      - generic [ref=f1e15]: Супер-адміністратор
      - button "Вийти" [ref=f1e16] [cursor=pointer]
  - main [ref=f1e17]:
    - generic [ref=f1e18]:
      - navigation [ref=f1e20]:
        - link "Магазини" [ref=f1e21] [cursor=pointer]:
          - /url: /stores
        - generic [ref=f1e22]: →
        - link "Харків, філія 2229" [ref=f1e23] [cursor=pointer]:
          - /url: /stores/1edb6b7a-923a-6e7c-b584-d75aa99ef07d
        - generic [ref=f1e24]: →
        - generic [ref=f1e25]: Слоти
      - generic [ref=f1e27]:
        - heading "Сільпо, вул. Яроша Отакара, 18д" [level=1] [ref=f1e28]
        - generic [ref=f1e29]:
          - generic [ref=f1e30]: "2229"
          - generic [ref=f1e31]: ·
          - generic [ref=f1e32]: Харків
          - generic [ref=f1e33]: ·
          - generic [ref=f1e34]: вул. Яроша Отакара, 18д
      - generic [ref=f1e35]:
        - button "Загальне" [ref=f1e36] [cursor=pointer]
        - button "Прийом поставок" [ref=f1e37] [cursor=pointer]
        - button "Слоти" [ref=f1e38] [cursor=pointer]
        - button "Обмеження" [ref=f1e39] [cursor=pointer]
        - button "Резерви" [ref=f1e40] [cursor=pointer]
        - button "Блокування слотів" [ref=f1e41] [cursor=pointer]
      - generic [ref=f1e42]:
        - generic [ref=f1e43]:
          - generic [ref=f1e44]: Розмір слоту
          - generic [ref=f1e45]:
            - generic [ref=f1e46]:
              - generic [ref=f1e47]: Розмір слоту
              - combobox "Розмір слоту" [ref=f1e48]:
                - option "не задано" [selected]
                - option "15 хв"
                - option "20 хв"
                - option "30 хв"
                - option "60 хв"
            - generic [ref=f1e49]:
              - generic [ref=f1e50]: Попередній перегляд сітки слотів
              - generic [ref=f1e51]:
                - generic [ref=f1e52]: "90"
                - generic [ref=f1e53]: "Слотів на день: 90"
        - generic [ref=f1e54]:
          - generic [ref=f1e55]: Рампи
          - generic [ref=f1e56]: Номер рампи — ціле число ≥ 1, унікальне в межах магазину
          - table [ref=f1e58]:
            - rowgroup [ref=f1e59]:
              - row [ref=f1e60]:
                - columnheader "Номер" [ref=f1e61]
                - columnheader "Назва" [ref=f1e62]
                - columnheader "Увімкнена" [ref=f1e63]
                - columnheader [ref=f1e64]
            - rowgroup [ref=f1e65]:
              - row [ref=f1e66]:
                - cell [ref=f1e67]:
                  - spinbutton [ref=f1e68]: "1"
                - cell [ref=f1e69]:
                  - textbox "Рампа №1" [ref=f1e70]: Рампа 1
                - cell [ref=f1e71]:
                  - generic [ref=f1e72] [cursor=pointer]:
                    - checkbox "Так" [checked] [ref=f1e73]
                    - text: Так
                - cell [ref=f1e74]:
                  - button "Видалити" [ref=f1e75] [cursor=pointer]
              - row [ref=f1e76]:
                - cell [ref=f1e77]:
                  - spinbutton [active] [ref=f1e78]: "1"
                - cell [ref=f1e79]:
                  - textbox "Рампа №1" [ref=f1e80]: Рампа 2
                - cell [ref=f1e81]:
                  - generic [ref=f1e82] [cursor=pointer]:
                    - checkbox "Так" [checked] [ref=f1e83]
                    - text: Так
                - cell [ref=f1e84]:
                  - button "Видалити" [ref=f1e85] [cursor=pointer]
              - row [ref=f1e86]:
                - cell [ref=f1e87]:
                  - spinbutton [ref=f1e88]: "3"
                - cell [ref=f1e89]:
                  - textbox "Рампа №3" [ref=f1e90]: Рампа 3
                - cell [ref=f1e91]:
                  - generic [ref=f1e92] [cursor=pointer]:
                    - checkbox "Так" [checked] [ref=f1e93]
                    - text: Так
                - cell [ref=f1e94]:
                  - button "Видалити" [ref=f1e95] [cursor=pointer]
          - button "Додати рампу" [ref=f1e96] [cursor=pointer]
      - generic [ref=f1e98]:
        - generic [ref=f1e99]:
          - generic [ref=f1e100]: Набирає чинності з
          - textbox "Набирає чинності з" [ref=f1e101]: 2026-08-29
          - generic [ref=f1e102]: Не раніше завтра; для ПЕРШОЇ версії конфігурації допускається сьогодні
          - generic [ref=f1e103]: Номер рампи — ціле число ≥ 1, унікальне в межах магазину
        - button "Зберегти" [disabled] [ref=f1e104]
```

# Test source

```ts
  514 |       'не задано',
  515 |       '15 хв',
  516 |       '20 хв',
  517 |       '30 хв',
  518 |       '60 хв',
  519 |     ]);
  520 |     expect(
  521 |       options.some((o) => o.includes('45')),
  522 |       'значення 45 хв не має бути доступним',
  523 |     ).toBe(false);
  524 |   });
  525 | 
  526 |   test('A-05.2 усі чотири розміри слоту можна обрати', async ({ page }) => {
  527 |     await openStore(page, '2229');
  528 |     await openTab(page, 'Слоти');
  529 |     for (const size of ['15', '20', '30', '60']) {
  530 |       await page.locator('#slot-size').selectOption(size);
  531 |       await expect(page.locator('#slot-size')).toHaveValue(size);
  532 |     }
  533 |   });
  534 | 
  535 |   test('A-05.3 спроба надіслати розмір 45 хв відхиляється бекендом', async ({ page: _page }) => {
  536 |     const store = await sandboxStore('2229');
  537 |     const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
  538 |     const tomorrow = kyivDay(2);
  539 | 
  540 |     const res = await apiRaw('post', `/stores/${store.branchId}/configurations`, {
  541 |       ...config,
  542 |       effectiveFrom: tomorrow,
  543 |       slotSizeMinutes: 45,
  544 |       ramps: config.ramps.map((r: any) => ({ ...r })),
  545 |     });
  546 |     expect(res.status, 'розмір слоту 45 хв має бути відхилений').toBe(422);
  547 |     expect(JSON.stringify(res.body)).toContain('15, 20, 30, 60');
  548 |   });
  549 | 
  550 |   test('A-05.4 рампи з конфігурації показані у таблиці', async ({ page }) => {
  551 |     const store = await openStore(page, '2229');
  552 |     const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
  553 |     await openTab(page, 'Слоти');
  554 | 
  555 |     const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
  556 |     await expect(rows, 'кількість рамп збігається з конфігурацією').toHaveCount(
  557 |       config.ramps.length,
  558 |     );
  559 |     for (let i = 0; i < config.ramps.length; i += 1) {
  560 |       await expect(rows.nth(i).locator('input[type=number]')).toHaveValue(
  561 |         String(config.ramps[i].number),
  562 |       );
  563 |       await expect(rows.nth(i).locator('input[type=text]')).toHaveValue(
  564 |         config.ramps[i].name ?? '',
  565 |       );
  566 |     }
  567 |   });
  568 | 
  569 |   test('A-05.5 додавання рампи', async ({ page }) => {
  570 |     await openStore(page, '2229');
  571 |     await openTab(page, 'Слоти');
  572 |     const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
  573 |     const before = await rows.count();
  574 | 
  575 |     await page.locator('button', { hasText: 'Додати рампу' }).click();
  576 |     await expect(rows).toHaveCount(before + 1);
  577 |     await expect(rows.last().locator('input[type=number]')).toHaveValue(String(before + 1));
  578 |   });
  579 | 
  580 |   test('A-05.6 назва рампи обмежена 60 символами', async ({ page }) => {
  581 |     await openStore(page, '2229');
  582 |     await openTab(page, 'Слоти');
  583 |     const nameInput = page
  584 |       .locator('.card', { hasText: 'Рампи' })
  585 |       .locator('table.data tbody tr')
  586 |       .first()
  587 |       .locator('input[type=text]');
  588 | 
  589 |     await nameInput.fill('Р'.repeat(60));
  590 |     expect((await nameInput.inputValue()).length, '60 символів — припустимо').toBe(60);
  591 |     expect(
  592 |       (await fieldErrors(page)).some((e) => e.includes('до 60 символів')),
  593 |       'на 60 символах помилки бути не має',
  594 |     ).toBe(false);
  595 | 
  596 |     await nameInput.fill('Р'.repeat(61));
  597 |     const value = await nameInput.inputValue();
  598 |     const hasError = (await fieldErrors(page)).some((e) => e.includes('до 60 символів'));
  599 |     expect(
  600 |       value.length <= 60 || hasError,
  601 |       `61 символ має бути або обрізаний, або позначений помилкою (довжина=${value.length}, помилка=${hasError})`,
  602 |     ).toBe(true);
  603 |   });
  604 | 
  605 |   test('A-05.7 дублікат номера рампи відхиляється', async ({ page }) => {
  606 |     await openStore(page, '2229');
  607 |     await openTab(page, 'Слоти');
  608 |     const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
  609 |     expect(await rows.count(), 'для перевірки потрібні щонайменше дві рампи').toBeGreaterThan(1);
  610 | 
  611 |     const firstNumber = await rows.first().locator('input[type=number]').inputValue();
  612 |     await rows.nth(1).locator('input[type=number]').fill(firstNumber);
  613 | 
> 614 |     await expect(page.locator('.card', { hasText: 'Рампи' }).locator('.field-error')).toContainText(
      |                                                                                       ^ Error: expect(locator).toContainText(expected) failed
  615 |       'Номер рампи — ціле число ≥ 1, унікальне в межах магазину',
  616 |     );
  617 |   });
  618 | 
  619 |   test('A-05.8 номер рампи менший за 1 відхиляється', async ({ page }) => {
  620 |     await openStore(page, '2229');
  621 |     await openTab(page, 'Слоти');
  622 |     const rows = page.locator('.card', { hasText: 'Рампи' }).locator('table.data tbody tr');
  623 |     await rows.first().locator('input[type=number]').fill('0');
  624 |     await expect(page.locator('.card', { hasText: 'Рампи' }).locator('.field-error')).toContainText(
  625 |       'Номер рампи — ціле число ≥ 1',
  626 |     );
  627 |   });
  628 | 
  629 |   test('A-05.9 рампу можна вимкнути', async ({ page }) => {
  630 |     await openStore(page, '2229');
  631 |     await openTab(page, 'Слоти');
  632 |     const row = page
  633 |       .locator('.card', { hasText: 'Рампи' })
  634 |       .locator('table.data tbody tr')
  635 |       .first();
  636 |     const checkbox = row.locator('input[type=checkbox]');
  637 |     await expect(checkbox).toBeChecked();
  638 |     await checkbox.uncheck();
  639 |     await expect(checkbox).not.toBeChecked();
  640 |     await expect(row).toContainText('Ні');
  641 |   });
  642 | 
  643 |   test('A-05.10 видалення останньої рампи відхиляється', async ({ page }) => {
  644 |     await openStore(page, '2229');
  645 |     await openTab(page, 'Слоти');
  646 |     const card = page.locator('.card', { hasText: 'Рампи' });
  647 |     const rows = card.locator('table.data tbody tr');
  648 | 
  649 |     for (let n = await rows.count(); n > 0; n -= 1) {
  650 |       await rows.first().locator('button', { hasText: 'Видалити' }).click();
  651 |     }
  652 | 
  653 |     await expect(card, 'без жодної рампи має бути помилка').toContainText(
  654 |       'Потрібна щонайменше одна рампа',
  655 |     );
  656 |     await expect(
  657 |       page.locator('button.btn-primary', { hasText: 'Зберегти' }),
  658 |       'форма показує помилку «Потрібна щонайменше одна рампа», ' +
  659 |         'тож кнопка «Зберегти» має бути заблокована — як це зроблено на вкладці «Загальне»',
  660 |     ).toBeDisabled();
  661 |   });
  662 | 
  663 |   test('A-05.10б бекенд не приймає конфігурацію без рамп', async () => {
  664 |     const store = await sandboxStore('2229');
  665 |     const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
  666 |     const before = await apiGet<any>(`/stores/${store.branchId}/configurations`);
  667 | 
  668 |     const res = await apiRaw('post', `/stores/${store.branchId}/configurations`, {
  669 |       ...config,
  670 |       effectiveFrom: kyivDay(2),
  671 |       ramps: [],
  672 |     });
  673 |     expect(res.status, 'конфігурація без рамп має бути відхилена').toBe(422);
  674 |     expect(JSON.stringify(res.body)).toContain('рампу');
  675 | 
  676 |     const after = await apiGet<any>(`/stores/${store.branchId}/configurations`);
  677 |     expect(after.items.length, 'нової версії конфігурації не створено').toBe(before.items.length);
  678 |   });
  679 | 
  680 |   test('A-05.11 попередній перегляд сітки слотів рахує слоти правильно', async ({ page }) => {
  681 |     const store = await openStore(page, '2229');
  682 |     const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
  683 |     await openTab(page, 'Слоти');
  684 | 
  685 |     const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);
  686 |     const enabled = config.ramps.filter((r: any) => r.active).length;
  687 |     const perInterval = window.intervals.reduce((acc: number, i: any) => {
  688 |       const [fh, fm] = i.from.split(':').map(Number);
  689 |       const [th, tm] = i.to.split(':').map(Number);
  690 |       return acc + Math.floor((th * 60 + tm - fh * 60 - fm) / config.slotSizeMinutes);
  691 |     }, 0);
  692 | 
  693 |     await expect(page.locator('.kpi-value')).toHaveText(String(perInterval * enabled));
  694 |   });
  695 | });
  696 | 
```