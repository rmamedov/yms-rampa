/**
 * Підготовка даних для тестів застосунку водія.
 *
 * Усе створюється рівно тим самим шляхом, яким користується постачальник:
 * REST кабінету постачальника (див. infra/e2e-check.py). Жодних прямих
 * записів у базу — інакше тест перевіряв би вигаданий стан, а не той, що
 * його справді породжує продукт.
 *
 * Маркери тестових даних (розділ 3 плану):
 *   водій     — телефон із діапазону +38099000XXXX
 *   авто      — держномер UT<4 цифри>XX
 *   бронювання — orderId `UITEST-<мітка>`
 */
import { APIRequestContext, Page, expect } from '@playwright/test';
import { ARRIVAL_SANDBOX_EXTERNAL_ID, CREDS, HOSTS, registerArtifact } from './env';

// --- Дати в календарі Києва -------------------------------------------------

const KYIV_DATE = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Kyiv',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
});

/** Поточна київська дата у форматі YYYY-MM-DD — так само, як рахує застосунок. */
export function kyivDateKey(at: Date | number = Date.now()): string {
  return KYIV_DATE.format(new Date(at));
}

/** Зсув київської дати на n днів. */
export function addDays(dateKey: string, days: number): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d) + days * 86_400_000);
  return `${dt.getUTCFullYear()}-${String(dt.getUTCMonth() + 1).padStart(2, '0')}-${String(
    dt.getUTCDate(),
  ).padStart(2, '0')}`;
}

// --- Токени -----------------------------------------------------------------

export async function supplierAuth(ctx: APIRequestContext): Promise<string> {
  const res = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/auth/login`, { data: CREDS.supplier });
  if (!res.ok()) throw new Error(`Вхід постачальника: ${res.status()} ${await res.text()}`);
  return (await res.json()).accessToken;
}

/** Токен співробітника мережі у контурі магазину (для звірки дій водія). */
export async function storeStaffAuth(ctx: APIRequestContext): Promise<string> {
  const res = await ctx.post(`${HOSTS.store}/api/store/v1/auth/login`, { data: { email: CREDS.admin.email, password: CREDS.admin.password } });
  if (!res.ok()) throw new Error(`Вхід співробітника: ${res.status()} ${await res.text()}`);
  return (await res.json()).accessToken;
}

export async function driverAuth(ctx: APIRequestContext, phone: string, password: string): Promise<string> {
  const res = await ctx.post(`${HOSTS.driver}/api/driver/v1/auth/login`, { data: { phone, password } });
  if (!res.ok()) throw new Error(`Вхід водія ${phone}: ${res.status()} ${await res.text()}`);
  return (await res.json()).accessToken;
}

const bearer = (token: string) => ({ Authorization: `Bearer ${token}` });

// --- Водії ------------------------------------------------------------------

export interface TestDriver {
  readonly id: string;
  readonly phone: string;
  /** Пароль повертається бекендом РІВНО ОДИН РАЗ — тримаємо його тут. */
  readonly password: string;
  readonly firstName: string;
  readonly lastName: string;
}

/** Унікальна мітка запуску: іде в orderId, ПІБ і номер авто. */
export function mark(): string {
  return String(Date.now()).slice(-6) + String(Math.floor(Math.random() * 90) + 10);
}

/**
 * Створює водія маркованим телефоном +38099000XXXX. Телефон вибирається
 * випадково з діапазону; на зайнятому номері бекенд віддає 409/422 і ми
 * пробуємо наступний.
 */
export async function createDriver(ctx: APIRequestContext, token: string, label: string): Promise<TestDriver> {
  for (let attempt = 0; attempt < 8; attempt += 1) {
    const phone = `+38099000${String(Math.floor(Math.random() * 10_000)).padStart(4, '0')}`;
    const firstName = 'UITEST';
    const lastName = `Водій-${label}`;
    const res = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/drivers`, {
      headers: bearer(token),
      data: { phone, firstName, lastName },
    });
    if (res.status() === 201) {
      const body = await res.json();
      createdDrivers.push(body.id);
      registerArtifact('driver', body.id, `${phone} ${firstName} ${lastName}`);
      return { id: body.id, phone, password: body.password, firstName, lastName };
    }
    if (res.status() !== 409 && res.status() !== 422) {
      throw new Error(`Створення водія: ${res.status()} ${await res.text()}`);
    }
  }
  throw new Error('Не вдалося підібрати вільний телефон у діапазоні +38099000XXXX');
}

// --- Магазини і бронювання --------------------------------------------------

export interface CatalogStore {
  readonly storeId: string;
  readonly externalId: string;
  readonly name: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: number | null;
  readonly longitude: number | null;
  readonly ramps: readonly { rampId: string; number: number; name: string }[];
  readonly maxVehicleWeightTons: number;
}

export interface TestBooking {
  readonly bookingId: string;
  readonly orderId: string;
  readonly plateNumber: string;
  readonly palletsCount: number;
  readonly rampId: string;
  readonly slotStart: string;
  /** Час слоту «HH:MM» у Києві — так само, як його рахує бекенд. */
  readonly localTime: string;
  readonly date: string;
  readonly store: CatalogStore;
}

/** Слоти, зайняті цим запуском: щоб два бронювання не билися за один слот. */
const takenSlots = new Set<string>();

/**
 * Усі бронювання, створені цим запуском. Постачальник має ліміт 50 активних
 * бронювань (BOOKING_LIMIT_EXCEEDED), тож набір ЗОБОВʼЯЗАНИЙ прибирати за
 * собою — інакше другий прогін просто не стартує.
 */
export const createdBookings: string[] = [];

/** Водії, створені цим запуском — деактивуються після прогону. */
export const createdDrivers: string[] = [];

export async function kyivStores(ctx: APIRequestContext, token: string): Promise<CatalogStore[]> {
  const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Київ')}`, {
    headers: bearer(token),
  });
  if (!res.ok()) throw new Error(`Каталог магазинів: ${res.status()}`);
  return (await res.json()).items as CatalogStore[];
}

/** Ширина вікна відмітки «На місці» — StorePolicy::ARRIVAL_WINDOW_MINUTES. */
export const ARRIVAL_WINDOW_MINUTES = 60;

/**
 * Філія, у якій відмітку «На місці» можна перевіряти о будь-якій годині:
 * цілодобовий прийом і lead time 0 (див. ARRIVAL_SANDBOX_EXTERNAL_ID у env.ts).
 *
 * Повертається списком, бо саме список приймає createBooking. Перевірка тут
 * не формальна: якщо філію на стенді скинули або переналаштували, тест має
 * впасти з поясненням, ЩО саме відновити, а не з незрозумілим «немає слотів».
 */
export async function arrivalSandbox(
  ctx: APIRequestContext,
  token: string,
): Promise<CatalogStore[]> {
  const res = await ctx.get(
    `${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`,
    { headers: bearer(token) },
  );
  expect(res.ok(), `каталог філій Харкова: ${res.status()}`).toBeTruthy();

  const store = ((await res.json()).items as CatalogStore[]).find(
    (s) => s.externalId === ARRIVAL_SANDBOX_EXTERNAL_ID,
  );
  expect(
    store,
    `філія ${ARRIVAL_SANDBOX_EXTERNAL_ID} має бути активною і видимою постачальникам — ` +
      'без неї відмітку «На місці» неможливо перевірити поза годинами прийому ' +
      '(як відновити — див. коментар ARRIVAL_SANDBOX_EXTERNAL_ID у support/env.ts)',
  ).toBeTruthy();

  const grid = await ctx.get(
    `${HOSTS.supplier}/api/supplier/v1/stores/${store!.storeId}/slots?date=${kyivDateKey()}`,
    { headers: bearer(token) },
  );
  expect(grid.ok(), `сітка слотів ${ARRIVAL_SANDBOX_EXTERNAL_ID}: ${grid.status()}`).toBeTruthy();

  const body = await grid.json();
  const now = Date.parse(body.now ?? '') || Date.now();
  const open = ((body.slots ?? []) as GridSlot[]).filter(
    (s) =>
      s.state === 'available' &&
      s.selectable !== false &&
      Date.parse(s.slotStart) - ARRIVAL_WINDOW_MINUTES * 60_000 <= now,
  );
  expect(
    open.length,
    `у філії ${ARRIVAL_SANDBOX_EXTERNAL_ID} має бути слот із уже відкритим вікном відмітки ` +
      `(lead time ${body.leadTimeMinutes} хв, вільних слотів ${(body.slots ?? []).length}); ` +
      'саме заради цього вона налаштована цілодобово',
  ).toBeGreaterThan(0);

  return [store!];
}

interface GridSlot {
  readonly rampId: string;
  readonly slotStart: string;
  readonly state: string;
  readonly selectable?: boolean;
}

interface SlotCandidate {
  readonly store: CatalogStore;
  readonly slot: GridSlot;
}

/**
 * Кандидати на бронювання, впорядковані під потребу тесту.
 *
 * `first`/`last` — по одному кандидату на філію в порядку каталогу
 * (історична поведінка: найраніший або найпізніший вільний слот філії).
 *
 * `soonest` — усі вільні слоти всіх філій, і спершу ті, чиє ВІКНО ВІДМІТКИ
 * вже відкрите (`slotStart − 60 хв ≤ зараз`). Саме такий слот потрібен
 * тестам, які доводять точку до «На місці».
 *
 * ЧОМУ ЦЕ НЕ ЗАВЖДИ МОЖЛИВО. Lead time бронювання і ширина вікна відмітки
 * однакові — 60 хв. Отже найраніший слот, який узагалі можна забронювати,
 * лежить рівно на межі вікна, і «вже відкритий» слот трапляється лише там,
 * де lead time філії менший за 60 хв (на стенді це філія 1995, lead 5 хв).
 * Якщо такої філії серед відчинених немає, повертається найближчий слот —
 * і його вікно відкриється за кілька хвилин, а не миттєво.
 */
async function slotCandidates(
  ctx: APIRequestContext,
  token: string,
  stores: CatalogStore[],
  date: string,
  which: 'first' | 'last' | 'soonest',
): Promise<SlotCandidate[]> {
  const candidates: SlotCandidate[] = [];
  let now = Date.now();

  for (const store of stores) {
    const res = await ctx.get(
      `${HOSTS.supplier}/api/supplier/v1/stores/${store.storeId}/slots?date=${date}`,
      { headers: bearer(token) },
    );
    if (!res.ok()) continue;
    const grid = await res.json();
    // Час беремо з відповіді сітки — це годинник сервера, а не машини тесту.
    now = Date.parse(grid.now ?? '') || now;

    const free = ((grid.slots ?? []) as GridSlot[])
      .filter((s) => s.state === 'available')
      .filter((s) => !takenSlots.has(`${store.storeId}|${s.rampId}|${s.slotStart}`))
      .sort((a, b) => a.slotStart.localeCompare(b.slotStart));
    if (free.length === 0) continue;

    if (which === 'soonest') {
      // Слоти, які сітка вже не дає бронювати (lead time), відсіюємо тут —
      // інакше вони з'їдали б спроби перед справді доступними.
      candidates.push(
        ...free.filter((s) => s.selectable !== false).map((slot) => ({ store, slot })),
      );
    } else {
      candidates.push({ store, slot: which === 'last' ? free[free.length - 1] : free[0] });
    }
  }

  if (which !== 'soonest') {
    return candidates;
  }

  const windowOpen = (slot: GridSlot): boolean =>
    Date.parse(slot.slotStart) - ARRIVAL_WINDOW_MINUTES * 60_000 <= now;

  return candidates.sort((a, b) => {
    const byWindow = Number(windowOpen(b.slot)) - Number(windowOpen(a.slot));
    return byWindow !== 0 ? byWindow : a.slot.slotStart.localeCompare(b.slot.slotStart);
  });
}

/**
 * Бронює вільний слот і призначає на нього водія.
 *
 * `which: 'last'` бере найпізніший вільний слот дня — це потрібно, щоб
 * створювати точки НЕ в хронологічному порядку і чесно перевірити
 * сортування маршрутного листа.
 *
 * `which: 'soonest'` бере слот, чиє вікно відмітки «На місці» вже відкрите
 * (а якщо такого немає — найближчий), перебираючи ВСІ філії набору. Саме він
 * потрібен тестам, які доводять точку до «На місці»: відмітка приймається
 * лише у вікні «slotStart − 60 хв … кінець слоту» (розділ 8). Подробиці
 * і межі можливого — у slotCandidates().
 */
export async function createBooking(
  ctx: APIRequestContext,
  token: string,
  options: {
    date: string;
    driverId: string;
    label: string;
    which?: 'first' | 'last' | 'soonest';
    palletsCount?: number;
    stores?: CatalogStore[];
  },
): Promise<TestBooking> {
  const stores = options.stores ?? (await kyivStores(ctx, token));
  const palletsCount = options.palletsCount ?? 12;
  const orderId = `UITEST-${options.label}`;
  const plateNumber = `UT${String(Math.floor(Math.random() * 9000) + 1000)}XX`;

  const candidates = await slotCandidates(
    ctx,
    token,
    stores,
    options.date,
    options.which ?? 'first',
  );
  /** Кандидати, яких бекенд не прийняв, — для зрозумілого повідомлення. */
  const rejected: string[] = [];

  for (const { store, slot } of candidates) {
    if (takenSlots.has(`${store.storeId}|${slot.rampId}|${slot.slotStart}`)) continue;

    const key = { storeId: store.storeId, rampId: slot.rampId, slotStart: slot.slotStart };
    takenSlots.add(`${store.storeId}|${slot.rampId}|${slot.slotStart}`);

    const holdRes = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/slots/hold`, {
      headers: bearer(token),
      data: key,
    });
    if (holdRes.status() !== 201) continue;
    const { holdToken } = await holdRes.json();

    // Тоннаж підбирається під ліміт саме цієї філії: у різних магазинів він
    // різний (10 і 20 т), і фіксовані 12.5 т ламали б бронювання в частині з них.
    const weightTons = Math.min(12.5, Math.max(1, (store.maxVehicleWeightTons ?? 20) - 0.5));

    const bookRes = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/bookings`, {
      headers: bearer(token),
      data: {
        ...key,
        holdToken,
        palletsCount,
        orderId,
        vehicle: { plateNumber, weightTons, brand: 'MAN TGX' },
      },
    });
    if (!bookRes.ok()) {
      const detail = normalize(await bookRes.text());
      // Слот міг стати недоступним між читанням сітки і бронюванням (його
      // перехопили, або він уже не проходить за lead time) — беремо наступного
      // кандидата. Решта відмов — справжні помилки, і мовчати про них не можна.
      if (bookRes.status() === 409 || bookRes.status() === 422) {
        rejected.push(`${store.externalId} ${slot.slotStart}: ${bookRes.status()} ${detail.slice(0, 120)}`);
        continue;
      }
      throw new Error(`Створення бронювання: ${bookRes.status()} ${detail}`);
    }
    const booking = await bookRes.json();
    const bookingId = booking.id ?? booking.bookingId;
    createdBookings.push(bookingId);
    registerArtifact('booking', bookingId, `${orderId} ${options.date} ${store.externalId}`);
    registerArtifact('vehicle-plate', plateNumber, `бронювання ${bookingId}`);

    const assign = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/route-sheets/driver`, {
      headers: bearer(token),
      data: { date: options.date, bookingId, driverId: options.driverId },
    });
    if (!assign.ok()) {
      throw new Error(`Призначення водія: ${assign.status()} ${await assign.text()}`);
    }

    return {
      bookingId,
      orderId,
      plateNumber,
      palletsCount,
      rampId: slot.rampId,
      slotStart: slot.slotStart,
      localTime: booking.localTime,
      date: options.date,
      store,
    };
  }
  throw new Error(
    `Немає вільних слотів на ${options.date} у жодній київській філії` +
      (rejected.length > 0 ? `; відмови бекенду: ${rejected.slice(0, 5).join(' | ')}` : ''),
  );
}

/** Текст відповіді в один рядок — щоб повідомлення про помилку читалося. */
function normalize(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}

/** Точки маршрутного листа водія прямо з API — еталон для звірки з екраном. */
export async function driverRouteSheet(
  ctx: APIRequestContext,
  driverToken: string,
  date: string,
): Promise<Record<string, unknown>[]> {
  const res = await ctx.get(`${HOSTS.driver}/api/driver/v1/route-sheet?date=${date}`, {
    headers: bearer(driverToken),
  });
  if (!res.ok()) throw new Error(`Маршрутний лист водія: ${res.status()} ${await res.text()}`);
  const body = await res.json();
  return (body.routeSheets ?? []).flatMap((s: { points: Record<string, unknown>[] }) => s.points);
}

// --- Дії контуру магазину (звірка результату дій водія) ---------------------

/**
 * Контур магазину не має жодного GET (див. web/apps/store-web/.../gateways.ts),
 * тому статус бронювання «очима магазину» читається єдиним доступним
 * способом — спробою переходу. `arrived → arrived` бекенд відхиляє з 409
 * INVALID_STATUS_TRANSITION, і саме ця відмова доводить, що магазин бачить
 * бронювання вже прибулим. Стан при цьому не змінюється.
 */
export async function storeSeesStatus(
  ctx: APIRequestContext,
  staffToken: string,
  bookingId: string,
): Promise<{ status: number; from?: string; to?: string; code?: string }> {
  const res = await ctx.post(`${HOSTS.store}/api/store/v1/bookings/${bookingId}/arrived`, {
    headers: bearer(staffToken),
    data: {},
  });
  const body = await res.json().catch(() => ({}));
  return { status: res.status(), ...body };
}

export async function storeStartUnloading(
  ctx: APIRequestContext,
  staffToken: string,
  bookingId: string,
): Promise<number> {
  const res = await ctx.post(`${HOSTS.store}/api/store/v1/bookings/${bookingId}/unloading`, {
    headers: bearer(staffToken),
    data: {},
  });
  return res.status();
}

// --- Прибирання -------------------------------------------------------------

/**
 * Звільняє бронювання, створені прогоном.
 *
 * `booked` скасовується постачальником. Точки, які тест довів до `arrived`
 * або `unloading`, скасувати вже не можна (машина станів це забороняє) —
 * їх доводить до `completed` магазин. І те, і те прибирає бронювання
 * з ліміту 50 активних.
 */
export async function releaseBookings(
  ctx: APIRequestContext,
  supplierToken: string,
  staffToken: string,
  bookingIds: readonly string[] = createdBookings,
): Promise<{ cancelled: number; completed: number; left: number }> {
  let cancelled = 0;
  let completed = 0;
  let left = 0;

  for (const id of bookingIds) {
    const del = await ctx.delete(`${HOSTS.supplier}/api/supplier/v1/bookings/${id}`, {
      headers: bearer(supplierToken),
      data: { reason: 'Прибирання тестових даних UITEST' },
    });
    if (del.ok()) {
      cancelled += 1;
      continue;
    }

    // Уже в дорозі — доводимо до кінця руками магазину.
    await ctx.post(`${HOSTS.store}/api/store/v1/bookings/${id}/unloading`, {
      headers: bearer(staffToken),
      data: {},
    });
    const done = await ctx.post(`${HOSTS.store}/api/store/v1/bookings/${id}/completed`, {
      headers: bearer(staffToken),
      data: {},
    });
    if (done.ok()) {
      completed += 1;
    } else {
      left += 1;
    }
  }

  return { cancelled, completed, left };
}

/**
 * Деактивує водіїв прогону (SUP-DRV-05). Видалення водія в API немає, тож
 * деактивація — максимум, що можна зробити з набору; історія зберігається,
 * а вхід у driver-web блокується.
 */
export async function releaseDrivers(
  ctx: APIRequestContext,
  supplierToken: string,
  driverIds: readonly string[] = createdDrivers,
): Promise<number> {
  let done = 0;
  for (const id of driverIds) {
    const res = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/drivers/${id}/deactivate`, {
      headers: bearer(supplierToken),
      data: {},
    });
    if (res.ok()) done += 1;
  }
  return done;
}

// --- UI ---------------------------------------------------------------------

/**
 * Перехоплення window.open: диплінк навігатора треба ПЕРЕВІРИТИ, а не
 * відкрити — інакше тест піде в зовнішню мережу. Скрипт ставиться до
 * завантаження сторінки і лише записує адреси.
 */
export async function captureExternalOpens(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const opened: string[] = [];
    (window as unknown as { __opened: string[] }).__opened = opened;
    window.open = ((url?: string | URL) => {
      opened.push(String(url ?? ''));
      return null;
    }) as typeof window.open;
  });
}

export async function openedUrls(page: Page): Promise<string[]> {
  return page.evaluate(() => (window as unknown as { __opened?: string[] }).__opened ?? []);
}

/** Вхід водія через інтерфейс. Повертає статус відповіді /auth/login. */
export async function driverLoginUi(page: Page, phone: string, password: string): Promise<number> {
  await page.goto(HOSTS.driver + '/login');
  await page.waitForSelector('input[type=tel]', { timeout: 30_000 });
  await page.locator('input[type=tel]').fill(phone);
  await page.locator('input[type=password]').fill(password);
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/auth/login') && r.request().method() === 'POST', {
      timeout: 30_000,
    }),
    page.locator('button[type=submit]').click(),
  ]);
  return response.status();
}

/** Вхід + очікування маршрутного листа. */
export async function openRouteSheet(page: Page, driver: TestDriver): Promise<void> {
  const status = await driverLoginUi(page, driver.phone, driver.password);
  expect(status, 'вхід водія має пройти').toBe(200);
  await page.waitForURL(/\/route$/, { timeout: 30_000 });
  await page.waitForResponse((r) => r.url().includes('/route-sheet'), { timeout: 30_000 }).catch(() => undefined);
  await page.waitForLoadState('networkidle');
}

/** Картка точки за номером бронювання (li#point-<id>). */
export function pointCard(page: Page, bookingId: string) {
  return page.locator(`#point-${bookingId}`);
}
