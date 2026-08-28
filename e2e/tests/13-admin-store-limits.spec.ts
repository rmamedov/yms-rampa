/**
 * A-06 «Обмеження», A-07 «Резерви», A-08 «Блокування», A-09 конфлікти конфігурації.
 *
 * Межі значень звіряються з бекендом (store-service/StoreConfiguration):
 * тоннаж 1.0–40.0 крок 0.5, lead time 0–1440 хв, горизонт 1–30 днів,
 * пільговий час 0–240 хв, утримання слоту 1–60 хв.
 */
import { expect, test } from '@playwright/test';
import {
  apiGet,
  apiRaw,
  apiSuppliers,
  fieldErrors,
  goto,
  kyivDay,
  loginAdmin,
  openTab,
  sandboxStore,
  track,
  waitForToast,
} from '../support/admin';

/** Межі, які насправді приймає бекенд (джерело: StoreConfiguration::*). */
const BACKEND_LIMITS = {
  weight: { min: 1, max: 40, step: 0.5 },
  leadTimeMinutes: { min: 0, max: 1440 },
  bookingHorizonDays: { min: 1, max: 30 },
  noShowGraceMinutes: { min: 0, max: 240 },
  holdMaxMinutes: { min: 1, max: 60 },
};

test.beforeEach(async ({ page }) => {
  await loginAdmin(page);
});

async function openLimits(page: import('@playwright/test').Page, externalId: string) {
  const store = await sandboxStore(externalId);
  await goto(page, `/stores/${store.branchId}`);
  await page.locator('.section-nav').waitFor({ state: 'visible', timeout: 20_000 });
  await openTab(page, 'Обмеження');
  return store;
}

/** Дата у поясі магазину: саме її очікують поля «Набирає чинності з» тощо. */
const dayOffset = kyivDay;

// ------------------------------------------------------------------ A-06

test.describe('A-06 Вкладка «Обмеження»', () => {
  test('A-06.1 поля заповнені значеннями чинної конфігурації', async ({ page }) => {
    const store = await openLimits(page, '2230');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);

    await expect(page.locator('#max-weight')).toHaveValue(String(config.maxVehicleWeightTons));
    await expect(page.locator('#lead-time')).toHaveValue(String(config.leadTimeMinutes));
    await expect(page.locator('#horizon')).toHaveValue(String(config.bookingHorizonDays));
    await expect(page.locator('#no-show-grace')).toHaveValue(String(config.noShowGraceMinutes));
    await expect(page.locator('#hold-max')).toHaveValue(String(config.holdMaxMinutes));
  });

  test('A-06.2 тоннаж: 1.0 і 40.0 приймаються', async ({ page }) => {
    await openLimits(page, '2230');
    for (const value of ['1', '1.0', '40', '40.0', '20.5']) {
      await page.locator('#max-weight').fill(value);
      expect(
        (await fieldErrors(page)).filter((e) => e.includes('Тоннаж')),
        `тоннаж ${value} має прийматись`,
      ).toEqual([]);
    }
  });

  test('A-06.3 тоннаж: 0.5, 45 і крок 12.3 відхиляються', async ({ page }) => {
    await openLimits(page, '2230');
    for (const value of ['0.5', '45', '12.3', '0', '40.5']) {
      await page.locator('#max-weight').fill(value);
      await expect(
        page.locator('app-store-limits-tab .field-error'),
        `тоннаж ${value} має бути відхилений`,
      ).toContainText('Тоннаж — від 1.0 до 40.0 з кроком 0.5');
    }
  });

  test('A-06.4 атрибути поля тоннажу відповідають межам бекенду', async ({ page }) => {
    await openLimits(page, '2230');
    const field = page.locator('#max-weight');
    await expect(field).toHaveAttribute('min', String(BACKEND_LIMITS.weight.min));
    await expect(field).toHaveAttribute('max', String(BACKEND_LIMITS.weight.max));
    await expect(field).toHaveAttribute('step', String(BACKEND_LIMITS.weight.step));
  });

  test('A-06.5 lead time: 0 і 1440 приймаються', async ({ page }) => {
    await openLimits(page, '2230');
    for (const value of ['0', '1440', '60']) {
      await page.locator('#lead-time').fill(value);
      expect(
        (await fieldErrors(page)).filter((e) => e.includes('Lead time')),
        `lead time ${value} має прийматись`,
      ).toEqual([]);
    }
  });

  test('A-06.6 lead time поза межами бекенду (1441) відхиляється формою', async ({ page }) => {
    await openLimits(page, '2230');
    await page.locator('#lead-time').fill('1441');
    const errors = await fieldErrors(page);
    expect(
      errors.some((e) => e.includes('Lead time')),
      `1441 хв бекенд не приймає (діапазон 0–1440), форма має відхилити значення одразу; ` +
        `фактичні помилки на екрані: ${JSON.stringify(errors)}`,
    ).toBe(true);
  });

  test('A-06.7 атрибути поля lead time відповідають межам бекенду', async ({ page }) => {
    await openLimits(page, '2230');
    const field = page.locator('#lead-time');
    await expect(field).toHaveAttribute('min', String(BACKEND_LIMITS.leadTimeMinutes.min));
    await expect(
      field,
      'бекенд приймає максимум 1440 хв — саме це має стояти в полі',
    ).toHaveAttribute('max', String(BACKEND_LIMITS.leadTimeMinutes.max));
  });

  test('A-06.8 горизонт: 1 і 30 приймаються', async ({ page }) => {
    await openLimits(page, '2230');
    for (const value of ['1', '30', '14']) {
      await page.locator('#horizon').fill(value);
      expect(
        (await fieldErrors(page)).filter((e) => e.includes('Горизонт')),
        `горизонт ${value} має прийматись`,
      ).toEqual([]);
    }
  });

  test('A-06.9 горизонт поза межами бекенду (31 і 0) відхиляється формою', async ({ page }) => {
    await openLimits(page, '2230');
    await page.locator('#horizon').fill('0');
    expect(
      (await fieldErrors(page)).some((e) => e.includes('Горизонт')),
      'горизонт 0 днів має бути відхилений',
    ).toBe(true);

    await page.locator('#horizon').fill('31');
    const errors = await fieldErrors(page);
    expect(
      errors.some((e) => e.includes('Горизонт')),
      `бекенд приймає горизонт 1–30 днів, 31 має бути відхилено формою; ` +
        `фактичні помилки на екрані: ${JSON.stringify(errors)}`,
    ).toBe(true);
  });

  test('A-06.10 атрибути поля горизонту відповідають межам бекенду', async ({ page }) => {
    await openLimits(page, '2230');
    const field = page.locator('#horizon');
    await expect(field).toHaveAttribute('min', String(BACKEND_LIMITS.bookingHorizonDays.min));
    await expect(
      field,
      'бекенд приймає максимум 30 днів — саме це має стояти в полі',
    ).toHaveAttribute('max', String(BACKEND_LIMITS.bookingHorizonDays.max));
  });

  test('A-06.11 утримання слоту поза межами бекенду (61) відхиляється формою', async ({ page }) => {
    await openLimits(page, '2230');
    const field = page.locator('#hold-max');
    await expect(
      field,
      'бекенд приймає максимум 60 хв утримання слоту',
    ).toHaveAttribute('max', String(BACKEND_LIMITS.holdMaxMinutes.max));

    await field.fill('61');
    expect(
      (await fieldErrors(page)).some((e) => e.includes('Утримання слоту')),
      'утримання слоту 61 хв бекенд не приймає',
    ).toBe(true);
  });

  test('A-06.12 пільговий час: межі 0 і 240, за межами — відмова', async ({ page }) => {
    await openLimits(page, '2230');
    for (const value of ['0', '240']) {
      await page.locator('#no-show-grace').fill(value);
      expect(
        (await fieldErrors(page)).filter((e) => e.includes('Пільговий час')),
        `пільговий час ${value} має прийматись`,
      ).toEqual([]);
    }
    await page.locator('#no-show-grace').fill('241');
    expect(
      (await fieldErrors(page)).some((e) => e.includes('Пільговий час')),
      'пільговий час 241 хв має бути відхилений',
    ).toBe(true);
  });

  test('A-06.13 некоректний тоннаж не потрапляє на бекенд і дані не змінюються', async ({
    page,
  }) => {
    const store = await openLimits(page, '2230');
    const before = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);

    await page.locator('#max-weight').fill('45');
    const save = page.locator('button.btn-primary', { hasText: /Зберегти|Завантаження/ });
    if (await save.isEnabled()) {
      await save.click();
      const toast = await waitForToast(page).catch(() => '');
      expect(toast.length, 'відмова має бути показана користувачу текстом').toBeGreaterThan(0);
    }

    const after = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    expect(after.maxVehicleWeightTons, 'некоректний тоннаж не збережено').toBe(
      before.maxVehicleWeightTons,
    );
    expect(after.version, 'нової версії конфігурації не створено').toBe(before.version);
  });

  test('A-06.14 коректна зміна обмежень зберігається новою версією', async ({ page }) => {
    const store = await sandboxStore('2230');
    const before = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    track('store-config', store.externalId, `нова версія обмежень, було lead=${before.leadTimeMinutes}`);

    const target = before.leadTimeMinutes === 90 ? 75 : 90;

    await goto(page, `/stores/${store.branchId}`);
    await page.locator('.section-nav').waitFor({ state: 'visible' });
    await openTab(page, 'Обмеження');
    await page.locator('#lead-time').fill(String(target));
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    expect(await waitForToast(page)).toContain('Конфігурацію збережено');

    // Бекенд віддає версії від новішої до старішої — беремо максимальний номер.
    const versions = await apiGet<any>(`/stores/${store.branchId}/configurations`);
    const saved = versions.items.reduce((a: any, b: any) => (b.version > a.version ? b : a));
    expect(saved.version, 'створено саме нову версію').toBeGreaterThan(before.version);
    expect(saved.leadTimeMinutes, 'нова версія має новий lead time').toBe(target);

    // повертаємо вихідне значення ще однією версією
    await goto(page, `/stores/${store.branchId}`);
    await openTab(page, 'Обмеження');
    await page.locator('#lead-time').fill(String(before.leadTimeMinutes));
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);

    const restored = await apiGet<any>(`/stores/${store.branchId}/configurations`);
    const latest = restored.items.reduce((a: any, b: any) => (b.version > a.version ? b : a));
    expect(latest.leadTimeMinutes, 'вихідне значення відновлено новою версією').toBe(
      before.leadTimeMinutes,
    );
  });

  /**
   * Поля «Набирає чинності з» більше немає: зміни застосовуються негайно.
   * Перевіряємо саме це — нова версія стає ЧИННОЮ одразу, без очікування
   * наступної доби.
   */
  test('A-06.15 збережена зміна діє негайно', async ({ page }) => {
    const store = await sandboxStore('2230');
    const before = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    track('store-config', store.externalId, `негайна чинність, було no-show=${before.noShowGraceMinutes}`);

    await expect(page.locator('#effective-from'), 'поля дати не має бути').toHaveCount(0);

    const target = before.noShowGraceMinutes === 30 ? 45 : 30;
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('.section-nav').waitFor({ state: 'visible' });
    await page.locator('#no-show-grace').fill(String(target));
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    expect(await waitForToast(page)).toContain('Конфігурацію збережено');

    // current, а не latest: перевіряємо саме чинність, а не факт збереження.
    const now = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    expect(now.noShowGraceMinutes, 'нова версія чинна одразу').toBe(target);
    expect(now.version, 'і це саме нова версія').toBeGreaterThan(before.version);

    // повертаємо як було
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('#no-show-grace').fill(String(before.noShowGraceMinutes));
    await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
    await waitForToast(page);
  });
});

// ------------------------------------------------------------------ A-07

test.describe('A-07 Вкладка «Резерви»', () => {
  async function openReserves(page: import('@playwright/test').Page, externalId: string) {
    const store = await sandboxStore(externalId);
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('.section-nav').waitFor({ state: 'visible', timeout: 20_000 });
    await openTab(page, 'Резерви');
    return store;
  }

  test('A-07.1 X-01 дропдаун постачальників містить УСІХ активних постачальників', async ({
    page,
  }) => {
    const suppliers = await apiSuppliers('limit=100&offset=0');
    const active = suppliers.items.filter((s) => s.status === 'active');
    expect(active.length, 'на стенді має бути хоч один активний постачальник').toBeGreaterThan(0);

    await openReserves(page, '2227');
    const options = (await page.locator('#res-supplier option').allInnerTexts()).map((s) =>
      s.trim(),
    );
    const missing = active.map((s) => s.name).filter((name) => !options.includes(name));

    expect(
      missing,
      `у списку ${options.length} постачальників, в API активних ${active.length}; ` +
        `немає: ${missing.join(', ')}`,
    ).toEqual([]);
  });

  test('A-07.2 у дропдауні рамп лише увімкнені рампи', async ({ page }) => {
    const store = await openReserves(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const enabled = config.ramps.filter((r: any) => r.active);

    const options = await page.locator('#res-ramp option').allInnerTexts();
    expect(options.length, 'кількість рамп у списку = кількість увімкнених').toBe(enabled.length);
  });

  test('A-07.3 резерв на день тижня створюється і видаляється', async ({ page }) => {
    const store = await openReserves(page, '2227');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);
    const startTime = window.intervals[0].from;

    // Тест має бути повторюваним: попередній прогін (або перерваний) міг
    // лишити правило на цей самий слот, і створення відхилялося б через
    // перетин — мовчки, бо ця перевірка клієнтська.
    const existing = await apiGet<any>(`/stores/${store.branchId}/reserved-slot-rules`);
    for (const rule of existing.items ?? []) {
      if (rule.slotStartTime === startTime && rule.dayOfWeek === 3) {
        await apiRaw('delete', `/stores/${store.branchId}/reserved-slot-rules/${rule.id}`);
      }
    }
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('.section-nav').waitFor({ state: 'visible' });

    await page.locator('#res-mode').selectOption('weekly');
    await page.locator('#res-day').selectOption('3');
    await page.locator('#res-time').fill(startTime);
    await page.locator('#res-from').fill(dayOffset(1));
    await page.locator('button', { hasText: 'Додати правило резерву' }).click();
    // Якщо форма відхилила локально — покажемо причину, а не мовчазний таймаут.
    const formErrors = await page.locator('app-store-reserves-tab .field-error').allInnerTexts();
    expect(formErrors, 'форма резерву не має відхиляти коректні дані').toEqual([]);
    await waitForToast(page);

    const rules = await apiGet<any>(`/stores/${store.branchId}/reserved-slot-rules`);
    const created = rules.items.find(
      (r: any) => r.slotStartTime === startTime && r.dayOfWeek === 3,
    );
    expect(created, 'правило резерву створено на бекенді').toBeTruthy();
    track('reserved-slot-rule', `${store.externalId}/${created.id}`, 'резерв на середу');

    await goto(page, `/stores/${store.branchId}`);
    await openTab(page, 'Резерви');
    // Секції видно одночасно, тож адресуємо саме таблицю резервів.
    const reserves = page.locator('app-store-reserves-tab table.data tbody');
    await expect(reserves).toContainText('Середа');

    await reserves
      .locator('tr', { hasText: 'Середа' })
      .first()
      .locator('button', { hasText: 'Видалити' })
      .click();
    await page.waitForTimeout(1500);

    const after = await apiGet<any>(`/stores/${store.branchId}/reserved-slot-rules`);
    expect(
      after.items.find((r: any) => r.id === created.id),
      'правило видалено з бекенду',
    ).toBeFalsy();
  });

  test('A-07.4 резерв поза вікном прийому відхиляється', async ({ page }) => {
    const store = await openReserves(page, '2227');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);
    expect(window.intervals[0].to < '22:00', 'вікно прийому має закінчуватись до 22:00').toBe(true);

    await page.locator('#res-mode').selectOption('weekly');
    await page.locator('#res-day').selectOption(String(window.dayOfWeek));
    await page.locator('#res-time').fill('22:00');
    await page.locator('button', { hasText: 'Додати правило резерву' }).click();

    await expect(page.locator('.field-error')).toContainText(
      'Час резерву не потрапляє в жодне вікно прийому',
    );
    const rules = await apiGet<any>(`/stores/${store.branchId}/reserved-slot-rules`);
    expect(
      rules.items.some((r: any) => r.slotStartTime === '22:00'),
      'правило на бекенді не створено',
    ).toBe(false);
  });

  test('A-07.5 перетин двох резервів відхиляється', async ({ page }) => {
    const store = await openReserves(page, '2229');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);
    const startTime = window.intervals[0].from;

    await page.locator('#res-mode').selectOption('weekly');
    await page.locator('#res-day').selectOption('4');
    await page.locator('#res-time').fill(startTime);
    await page.locator('#res-from').fill(dayOffset(1));
    await page.locator('button', { hasText: 'Додати правило резерву' }).click();
    await waitForToast(page);

    const rules = await apiGet<any>(`/stores/${store.branchId}/reserved-slot-rules`);
    const created = rules.items.find((r: any) => r.dayOfWeek === 4);
    expect(created, 'перше правило створено').toBeTruthy();
    track('reserved-slot-rule', `${store.externalId}/${created.id}`, 'перевірка перетину');

    // друге таке саме — має бути відхилене
    await page.locator('#res-mode').selectOption('weekly');
    await page.locator('#res-day').selectOption('4');
    await page.locator('#res-time').fill(startTime);
    await page.locator('button', { hasText: 'Додати правило резерву' }).click();
    await expect(page.locator('.field-error')).toContainText(
      'Перетин двох правил резерву на один слот заборонений',
    );

    // прибираємо за собою
    const del = await apiRaw(
      'delete',
      `/stores/${store.branchId}/reserved-slot-rules/${created.id}`,
    );
    expect(del.status).toBeLessThan(400);
  });

  test('A-07.6 «Діє по» раніше за «Діє з» відхиляється', async ({ page }) => {
    const store = await openReserves(page, '2227');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);

    await page.locator('#res-mode').selectOption('weekly');
    await page.locator('#res-day').selectOption(String(window.dayOfWeek));
    await page.locator('#res-time').fill(window.intervals[0].from);
    await page.locator('#res-from').fill(dayOffset(10));
    await page.locator('#res-to').fill(dayOffset(3));
    await page.locator('button', { hasText: 'Додати правило резерву' }).click();

    await expect(page.locator('.field-error')).toContainText(
      '«Діє по» не може бути раніше за «Діє з»',
    );
  });

  test('A-07.7 резерв на конкретну дату створюється і видаляється', async ({ page }) => {
    const store = await openReserves(page, '2226');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    // шукаємо найближчу дату, у якої день тижня має вікно прийому
    let date = '';
    for (let d = 1; d <= 10; d += 1) {
      const candidate = dayOffset(d);
      const parsed = new Date(`${candidate}T12:00:00Z`);
      const dow = parsed.getUTCDay() === 0 ? 7 : parsed.getUTCDay();
      if (config.receivingWindows.some((w: any) => w.dayOfWeek === dow && w.intervals.length)) {
        date = candidate;
        break;
      }
    }
    expect(date, 'має знайтись дата з вікном прийому').not.toBe('');
    const window = config.receivingWindows.find((w: any) => w.intervals.length > 0);

    await page.locator('#res-mode').selectOption('date');
    await page.locator('#res-date').fill(date);
    await page.locator('#res-time').fill(window.intervals[0].from);
    await page.locator('#res-from').fill(dayOffset(1));
    await page.locator('button', { hasText: 'Додати правило резерву' }).click();
    await waitForToast(page);

    const rules = await apiGet<any>(`/stores/${store.branchId}/reserved-slot-rules`);
    const created = rules.items.find((r: any) => r.date === date);
    expect(created, `резерв на дату ${date} створено`).toBeTruthy();
    track('reserved-slot-rule', `${store.externalId}/${created.id}`, `резерв на ${date}`);

    const del = await apiRaw(
      'delete',
      `/stores/${store.branchId}/reserved-slot-rules/${created.id}`,
    );
    expect(del.status).toBeLessThan(400);
  });
});

// ------------------------------------------------------------------ A-08

test.describe('A-08 Вкладка «Блокування слотів»', () => {
  async function openBlocks(page: import('@playwright/test').Page, externalId: string) {
    const store = await sandboxStore(externalId);
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('.section-nav').waitFor({ state: 'visible', timeout: 20_000 });
    await openTab(page, 'Блокування слотів');
    return store;
  }

  test('A-08.1 разове блокування з причиною створюється і знімається', async ({ page }) => {
    const store = await openBlocks(page, '2230');
    const reason = `UITEST блокування ${Date.now().toString(36)}`;

    await page.locator('#blk-date').fill(dayOffset(1));
    await page.locator('#blk-from').fill('10:00');
    await page.locator('#blk-to').fill('12:00');
    await page.locator('#blk-reason').fill(reason);
    await page.locator('button', { hasText: 'Додати блокування' }).click();
    await waitForToast(page);

    const blocks = await apiGet<any>(`/stores/${store.branchId}/slot-blocks`);
    const created = blocks.items.find((b: any) => b.reason === reason);
    expect(created, 'блокування створено на бекенді').toBeTruthy();
    track('slot-block', `${store.externalId}/${created.id}`, reason);

    await goto(page, `/stores/${store.branchId}`);
    await openTab(page, 'Блокування слотів');
    const row = page.locator('table.data tbody tr', { hasText: reason });
    await expect(row).toBeVisible();

    await row.locator('button', { hasText: 'Зняти блокування' }).click();
    await page.waitForTimeout(1500);

    const after = await apiGet<any>(`/stores/${store.branchId}/slot-blocks`);
    const released = after.items.find((b: any) => b.id === created.id);
    expect(released?.releasedAt, 'блокування знято').toBeTruthy();
  });

  test('A-08.2 блокування без причини відхиляється', async ({ page }) => {
    const store = await openBlocks(page, '2230');
    const before = await apiGet<any>(`/stores/${store.branchId}/slot-blocks`);

    await page.locator('#blk-date').fill(dayOffset(1));
    await page.locator('#blk-reason').fill('');
    await page.locator('button', { hasText: 'Додати блокування' }).click();

    await expect(page.locator('.field-error')).toContainText(
      'Причина обовʼязкова, до 200 символів',
    );
    const after = await apiGet<any>(`/stores/${store.branchId}/slot-blocks`);
    expect(after.items.length, 'блокування не створено').toBe(before.items.length);
  });

  test('A-08.3 блокування в минулому відхиляється', async ({ page }) => {
    await openBlocks(page, '2230');
    await page.locator('#blk-date').fill(dayOffset(-2));
    await page.locator('#blk-reason').fill('UITEST минуле');
    await page.locator('button', { hasText: 'Додати блокування' }).click();
    await expect(page.locator('.field-error')).toContainText(
      'Дата блокування не може бути в минулому',
    );
  });

  test('A-08.4 кінець блокування раніше початку відхиляється', async ({ page }) => {
    await openBlocks(page, '2230');
    await page.locator('#blk-date').fill(dayOffset(1));
    await page.locator('#blk-from').fill('15:00');
    await page.locator('#blk-to').fill('11:00');
    await page.locator('#blk-reason').fill('UITEST зворотний час');
    await page.locator('button', { hasText: 'Додати блокування' }).click();
    await expect(page.locator('.field-error')).toContainText('Початок має бути раніше за кінець');
  });

  test('A-08.5 блокування конкретних рамп', async ({ page }) => {
    const store = await openBlocks(page, '2226');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    expect(config.ramps.length, 'потрібно щонайменше дві рампи').toBeGreaterThan(1);

    const reason = `UITEST рампа ${Date.now().toString(36)}`;
    await page.locator('#blk-date').fill(dayOffset(2));
    await page.locator('#blk-from').fill('09:00');
    await page.locator('#blk-to').fill('10:00');
    await page.locator('.toolbar label.checkbox input[type=checkbox]').first().check();
    await page.locator('#blk-reason').fill(reason);
    await page.locator('button', { hasText: 'Додати блокування' }).click();
    await waitForToast(page);

    const blocks = await apiGet<any>(`/stores/${store.branchId}/slot-blocks`);
    const created = blocks.items.find((b: any) => b.reason === reason);
    expect(created, 'блокування створено').toBeTruthy();
    expect(created.rampIds.length, 'блокування стосується однієї рампи').toBe(1);
    track('slot-block', `${store.externalId}/${created.id}`, reason);

    await apiRaw('post', `/stores/${store.branchId}/slot-blocks/${created.id}/release`);
  });
});

// ------------------------------------------------------------------ A-09

test.describe('A-09 Конфлікти конфігурації', () => {
  test('A-09.1 незавершена конфігурація не зберігається', async ({ page }) => {
    const store = await sandboxStore('2229');
    await goto(page, `/stores/${store.branchId}`);
    await page.locator('.section-nav').waitFor({ state: 'visible' });
    await openTab(page, 'Слоти');
    await page.locator('#slot-size').fill('');

    await expect(page.locator('.field-error')).toContainText(
      'Щоб зберегти версію конфігурації, задайте розмір слоту і максимальний тоннаж',
    );
    await expect(
      page.locator('button.btn-primary', { hasText: 'Зберегти' }),
      'без розміру слоту зберігати не можна',
    ).toBeDisabled();
  });

  test('A-09.2 зменшення тоннажу попереджає про вплив на наявні бронювання', async ({ page }) => {
    const store = await openLimits(page, '2230');
    const config = await apiGet<any>(`/stores/${store.branchId}/configurations/current`);
    const lower = Math.max(1, config.maxVehicleWeightTons - 0.5);

    await page.locator('#max-weight').fill(String(lower));
    await expect(
      page.locator('.notice-warn'),
      'STC-31: зменшення ліміту зачіпає вже наявні бронювання',
    ).toContainText('Зменшення тоннажу може зачепити вже наявні бронювання');
  });

  test('A-09.3 конфлікти з бронюваннями при зміні сітки слотів', async () => {
    // Сценарій потребує чинного бронювання на філії, а бронювання створюються
    // лише в кабінеті постачальника (/api/supplier/v1). З адмін-панелі ані
    // створити умову, ані побачити перелік конфліктів неможливо: контракт
    // POST /stores/{id}/configurations конфліктів не повертає взагалі.
    test.skip(true, 'потрібне бронювання, створене поза адмін-панеллю (S-06)');
  });
});
