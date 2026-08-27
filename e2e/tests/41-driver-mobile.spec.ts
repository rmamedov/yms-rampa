/**
 * D-08 / X-10 — мобільна адаптація застосунку водія.
 *
 * Це профільна вимога саме цього застосунку: водій користується ним однією
 * рукою в кабіні. Тому перевіряються не «скріншоти», а вимірювані речі:
 *   • сторінка не має горизонтального скролу;
 *   • тач-цілі кнопок не менші за 44×44 px (нижня межа зручного дотику);
 *   • текст ніде не обрізаний рамкою свого блоку.
 *
 * Вʼюпорти задаються в самих тестах (test.use), щоб не чіпати спільний
 * playwright.config.ts, яким користуються решта наборів.
 */
import { Page, expect, test } from '@playwright/test';
import { HOSTS, api } from '../support/env';
import {
  TestBooking,
  TestDriver,
  captureExternalOpens,
  createBooking,
  createDriver,
  kyivDateKey,
  kyivStores,
  mark,
  openRouteSheet,
  pointCard,
  releaseBookings,
  releaseDrivers,
  storeStaffAuth,
  supplierAuth,
} from '../support/driver-data';

const MIN_TOUCH = 44;

let driver: TestDriver;
let booking: TestBooking;
let cleanup: { ctx: Awaited<ReturnType<typeof api>>; token: string } | null = null;

test.beforeAll(async () => {
  const ctx = await api();
  const token = await supplierAuth(ctx);
  const stores = await kyivStores(ctx, token);
  const label = mark();
  driver = await createDriver(ctx, token, `${label}-моб`);
  booking = await createBooking(ctx, token, {
    date: kyivDateKey(),
    driverId: driver.id,
    label: `${label}-моб`,
    which: 'last',
    stores,
  });
  cleanup = { ctx, token };
});

/** Ліміт 50 активних бронювань у постачальника — прибираємо за собою. */
test.afterAll(async () => {
  if (!cleanup) return;
  const staffToken = await storeStaffAuth(cleanup.ctx);
  const result = await releaseBookings(cleanup.ctx, cleanup.token, staffToken);
  const drivers = await releaseDrivers(cleanup.ctx, cleanup.token);
  console.log(
    `[UITEST] прибрано: скасовано ${result.cancelled} бронювань, завершено ${result.completed}; ` +
      `деактивовано водіїв ${drivers}`,
  );
});

// --- Вимірювання ------------------------------------------------------------

interface Small {
  readonly label: string;
  readonly width: number;
  readonly height: number;
}

/**
 * Видимі тач-цілі, менші за 44×44.
 *
 * Міряється ЗОНА АКТИВАЦІЇ, а не сам елемент: прапорець у `<label>`
 * перемикається дотиком у будь-яке місце свого рядка, тому цілью є label,
 * а не квадратик 24×24 усередині нього.
 */
async function smallTouchTargets(page: Page): Promise<Small[]> {
  return page.evaluate((min) => {
    const result: { label: string; width: number; height: number }[] = [];
    for (const el of Array.from(document.querySelectorAll('button, a[href], input[type=checkbox], input[type=radio]'))) {
      const style = getComputedStyle(el);
      if (style.visibility === 'hidden' || style.display === 'none') continue;
      // Підложка bottom-sheet — це не тач-ціль, а зона закриття на весь екран.
      if (el.classList.contains('backdrop')) continue;

      const target = el.tagName === 'INPUT' ? (el.closest('label') ?? el) : el;
      const box = target.getBoundingClientRect();
      if (box.width === 0 || box.height === 0) continue;

      if (box.width < min || box.height < min) {
        const text = (target as HTMLElement).innerText?.trim().slice(0, 40);
        result.push({
          label: `${target.tagName.toLowerCase()}.${target.className || '—'} «${text || (el as HTMLInputElement).type || ''}»`,
          width: Math.round(box.width),
          height: Math.round(box.height),
        });
      }
    }
    return result;
  }, MIN_TOUCH);
}

/**
 * Елементи, текст яких не вміщується у власну рамку.
 *
 * Ловляться обидві біди, а не лише `overflow: hidden`: при `hidden`/`clip`
 * текст обрізається, при `visible` — вилазить на сусідів і тягне за собою
 * горизонтальний скрол сторінки. Свідомо прокручувані смуги (`overflow-x:
 * auto`, як стрічка чипсів дат) — не дефект і виключені.
 *
 * Перевірка не порожня: на 200 px вона знаходить `dt` «РАМПА» (44 > 40 px).
 */
async function clippedText(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const clipped: string[] = [];
    const scrollable = (v: string) => v === 'auto' || v === 'scroll';
    for (const el of Array.from(document.querySelectorAll('body *'))) {
      const node = el as HTMLElement;
      if (node.children.length > 0) continue; // лише листові вузли з текстом
      const text = node.innerText?.trim();
      if (!text) continue;
      const style = getComputedStyle(node);
      const name = `${node.tagName.toLowerCase()}.${node.className || '—'}`;
      if (!scrollable(style.overflowX) && node.scrollWidth > node.clientWidth + 1) {
        clipped.push(`${name}: «${text.slice(0, 40)}» — ${node.scrollWidth} px тексту в ${node.clientWidth} px рамки`);
      } else if (!scrollable(style.overflowY) && node.scrollHeight > node.clientHeight + 1) {
        clipped.push(
          `${name}: «${text.slice(0, 40)}» — ${node.scrollHeight} px тексту у ${node.clientHeight} px по висоті`,
        );
      }
    }
    return clipped;
  });
}

async function noHorizontalScroll(page: Page): Promise<{ scroll: number; client: number }> {
  return page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }));
}

/**
 * Повний набір перевірок адаптації на поточному екрані.
 *
 * Перевірки мʼякі (expect.soft) навмисно: якщо на екрані і скрол, і дрібна
 * кнопка, звіт має показати обидві проблеми, а не першу-ліпшу.
 */
async function assertAdaptive(page: Page, screen: string, expectedWidth: number): Promise<void> {
  // Страховка від тихої втрати test.use({viewport}): якщо ширина не та,
  // усі подальші виміри нічого не варті.
  const innerWidth = await page.evaluate(() => window.innerWidth);
  expect(innerWidth, `${screen}: вʼюпорт не застосувався (фактично ${innerWidth} px)`).toBe(expectedWidth);

  const { scroll, client } = await noHorizontalScroll(page);
  expect
    .soft(scroll, `${screen}: горизонтальний скрол (${scroll} px вміст проти ${client} px екрана)`)
    .toBeLessThanOrEqual(client + 1);

  const small = await smallTouchTargets(page);
  expect
    .soft(
      small,
      `${screen}: тач-цілі менші за ${MIN_TOUCH}×${MIN_TOUCH}: ` +
        small.map((s) => `${s.label} — ${s.width}×${s.height}`).join('; '),
    )
    .toHaveLength(0);

  const clipped = await clippedText(page);
  expect.soft(clipped, `${screen}: обрізаний текст: ${clipped.join('; ')}`).toHaveLength(0);
}

// --- 360×740 (мобільний) ----------------------------------------------------

test.describe('D-08 мобільний 360×740', () => {
  test.use({ viewport: { width: 360, height: 740 } });

  test('D-08 екран входу', async ({ page }) => {
    await page.goto(HOSTS.driver + '/login');
    await page.waitForSelector('input[type=tel]');
    await assertAdaptive(page, 'вхід 360×740', 360);
  });

  test('D-08 маршрутний лист', async ({ page }) => {
    await openRouteSheet(page, driver);
    await expect(pointCard(page, booking.bookingId)).toBeVisible();
    await assertAdaptive(page, 'маршрутний лист 360×740', 360);
  });

  test('D-08 основні кнопки картки не менші за 44 px', async ({ page }) => {
    await openRouteSheet(page, driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();

    const main = [
      ['На місці', card.locator('button.btn.arrive')],
      ['Побудувати маршрут', card.locator('button.btn.primary.tall')],
      ['Номер замовлення', card.locator('button', { hasText: /№ замовлення/ })],
      ['Повідомити про затримку', card.locator('button', { hasText: 'Повідомити про затримку' })],
      ['Меню', page.locator('button.icon-btn')],
    ] as const;

    for (const [name, locator] of main) {
      const box = await locator.first().boundingBox();
      expect(box, `кнопка «${name}» має бути на екрані`).not.toBeNull();
      expect(box!.height, `висота кнопки «${name}» — ${Math.round(box!.height)} px`).toBeGreaterThanOrEqual(MIN_TOUCH);
      expect(box!.width, `ширина кнопки «${name}» — ${Math.round(box!.width)} px`).toBeGreaterThanOrEqual(MIN_TOUCH);
    }
  });

  test('D-08 нижня панель вибору навігатора', async ({ page }) => {
    await captureExternalOpens(page);
    await openRouteSheet(page, driver);
    await pointCard(page, booking.bookingId).locator('button.btn.primary.tall').click();
    await expect(page.locator('section.sheet[role=dialog]')).toBeVisible();
    await assertAdaptive(page, 'вибір навігатора 360×740', 360);
  });

  test('D-08 форма затримки', async ({ page }) => {
    await openRouteSheet(page, driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();
    await card.locator('button', { hasText: 'Повідомити про затримку' }).click();

    const sheet = page.locator('section.sheet[role=dialog]');
    await expect(sheet).toBeVisible();
    // Найгірший випадок: «Інше» додає поле коментаря і робить панель вищою.
    await sheet.locator('button.sheet-item', { hasText: 'Інше' }).click();
    await expect(sheet.locator('textarea.sheet-input')).toBeVisible();
    await assertAdaptive(page, 'форма затримки 360×740', 360);

    // Панель не має перекривати саму себе: кнопка надсилання лишається в екрані.
    const submit = await sheet.locator('button.sheet-item.primary').boundingBox();
    expect(submit, 'кнопка «Надіслати» має бути видимою').not.toBeNull();
    expect(submit!.y + submit!.height, 'кнопка «Надіслати» не має виходити за нижню межу екрана').toBeLessThanOrEqual(
      740,
    );
  });

  test('D-08 меню', async ({ page }) => {
    await openRouteSheet(page, driver);
    await page.locator('button.icon-btn').click();
    await expect(page.locator('section.sheet[role=dialog]')).toBeVisible();
    await assertAdaptive(page, 'меню 360×740', 360);
  });
});

// --- 768×1024 (планшет) -----------------------------------------------------

test.describe('D-08 планшет 768×1024', () => {
  test.use({ viewport: { width: 768, height: 1024 } });

  test('D-08 екран входу', async ({ page }) => {
    await page.goto(HOSTS.driver + '/login');
    await page.waitForSelector('input[type=tel]');
    await assertAdaptive(page, 'вхід 768×1024', 768);
  });

  test('D-08 маршрутний лист', async ({ page }) => {
    await openRouteSheet(page, driver);
    await expect(pointCard(page, booking.bookingId)).toBeVisible();
    await assertAdaptive(page, 'маршрутний лист 768×1024', 768);
  });

  test('D-08 форма затримки', async ({ page }) => {
    await openRouteSheet(page, driver);
    const card = pointCard(page, booking.bookingId);
    await card.scrollIntoViewIfNeeded();
    await card.locator('button', { hasText: 'Повідомити про затримку' }).click();
    const sheet = page.locator('section.sheet[role=dialog]');
    await sheet.locator('button.sheet-item', { hasText: 'Інше' }).click();
    await assertAdaptive(page, 'форма затримки 768×1024', 768);
  });
});

// --- Дуже вузький екран -----------------------------------------------------

test.describe('D-08 вузький екран 320×568', () => {
  test.use({ viewport: { width: 320, height: 568 } });

  test('D-08 маршрутний лист лишається читабельним на 320 px', async ({ page }) => {
    await openRouteSheet(page, driver);
    await expect(pointCard(page, booking.bookingId)).toBeVisible();
    await assertAdaptive(page, 'маршрутний лист 320×568', 320);
  });
});
