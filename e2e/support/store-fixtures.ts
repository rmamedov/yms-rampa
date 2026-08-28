/**
 * Підготовка даних для перевірок модуля магазину.
 *
 * Правило: бронювання створюються ЧЕРЕЗ API ПОСТАЧАЛЬНИКА (hold → booking),
 * а перевіряються через інтерфейс магазину. Так тест магазину не залежить від
 * інтерфейсу постачальника і не «зеленіє» через спільний баг у ньому.
 */
import { APIRequestContext, expect } from '@playwright/test';
import { ARRIVAL_SANDBOX_EXTERNAL_IDS, CREDS, HOSTS, registerArtifact } from './env';

/**
 * Пісочниця: філії Харкова. Інші перевірки на них не спираються, тож дані,
 * створені тут, нікому не заважають.
 */
export const SANDBOX_EXTERNAL_IDS = ['2226', '2227', '2229', '2230'] as const;

/** Ширина вікна відмітки «На місці» — StorePolicy::ARRIVAL_WINDOW_MINUTES. */
export const ARRIVAL_WINDOW_MINUTES = 60;

/**
 * Довідники причин. Значення взяті з бекенду — enum'ів booking-service
 * (`RejectionReason`, `DelayReason`, `PartialUnloadReason`), а не з фронтенду,
 * щоб тест ловив розходження між інтерфейсом і сервером.
 */
export const REJECT_REASONS = [
  'перевищення тоннажу',
  'невідповідність вантажу',
  'відсутні документи',
  'інше',
] as const;

export const DELAY_REASONS = [
  'затори',
  'поломка',
  'затримка на попередній точці',
  'інше',
] as const;

export const PARTIAL_UNLOAD_REASONS = [
  'немає місця',
  'бій/брак',
  'розбіжність із замовленням',
  'відмова частини вантажу',
  'інше',
] as const;

export interface Ramp {
  readonly rampId: string;
  readonly number?: number;
  readonly name: string;
  readonly active?: boolean;
}

export interface SandboxStore {
  readonly storeId: string;
  readonly externalId: string;
  readonly name: string;
  readonly city: string;
  readonly address: string;
  readonly ramps: readonly Ramp[];
  readonly maxVehicleWeightTons: number;
  readonly slotSizeMinutes: number;
}

export interface Slot {
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly localStart: string;
  readonly state: string;
  readonly selectable: boolean;
}

export interface Booking {
  readonly id: string;
  readonly type: string;
  readonly status: string;
  readonly storeId: string;
  readonly rampId: string;
  readonly slotStart: string;
  readonly slotEnd: string;
  readonly localDate: string;
  readonly localTime: string;
  readonly supplierId: string | null;
  readonly supplierName: string;
  readonly vehicle: { plateNumber: string; weightTons: number; brand: string | null };
  readonly orderId: string | null;
  readonly palletsCount: number;
  readonly statusHistory?: readonly { from: string | null; to: string; at: string; by: string }[];
}

/** Дата у київському поясі — незалежно від TZ процесу, що запускає тести. */
export function kyivDateKey(offsetDays = 0): string {
  const now = new Date(Date.now() + offsetDays * 86_400_000);
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(now);
}

/** Час HH:MM у київському поясі для ISO-моменту. */
export function kyivTime(iso: string): string {
  return new Intl.DateTimeFormat('uk-UA', {
    timeZone: 'Europe/Kyiv',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(iso));
}

let plateSeq = 0;

/**
 * Держномер тестового авто: UT<унікальний хвіст>XX (префікс UT — маркер для
 * прибирання, довжина в межах 4–12 символів, які приймає бекенд).
 *
 * Хвіст навмисно не «4 випадкові цифри»: у пісочниці за добу накопичуються
 * сотні тестових бронювань, а 9000 варіантів давали збіги — на дошці зʼявлялися
 * дві картки з одним номером, і перевірка бралася за чужу (наприклад, за вже
 * завершену, де дії магазину вже недоступні).
 */
export function testPlate(): string {
  plateSeq += 1;
  const tail = (Date.now().toString(36) + plateSeq.toString(36))
    .toUpperCase()
    .slice(-8);
  return `UT${tail}XX`;
}

/** orderId тестового бронювання: UITEST-<мітка>. */
export function testOrderId(label: string): string {
  return `UITEST-${label}-${Date.now().toString().slice(-5)}`;
}

// ---------------------------------------------------------------------------
// Токени
// ---------------------------------------------------------------------------

export interface StaffLogin {
  readonly accessToken: string;
  readonly user: {
    id: string;
    email: string;
    fullName: string;
    role: string;
    roleLabel: string;
    scope: { storeIds: string[]; networkWide: boolean };
    permissions: string[];
  };
}

/** Вхід у контур магазину (той самий обліковий запис, що й в адмінці). */
export async function storeLogin(ctx: APIRequestContext): Promise<StaffLogin> {
  const res = await ctx.post(`${HOSTS.store}/api/store/v1/auth/login`, {
    data: { email: CREDS.admin.email, password: CREDS.admin.password },
  });
  if (!res.ok()) {
    throw new Error(`Вхід у контур магазину не вдався: ${res.status()} ${await res.text()}`);
  }
  return (await res.json()) as StaffLogin;
}

export async function supplierAccessToken(ctx: APIRequestContext): Promise<string> {
  const res = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/auth/login`, {
    data: CREDS.supplier,
  });
  if (!res.ok()) throw new Error(`Вхід постачальника не вдався: ${res.status()}`);
  return (await res.json()).accessToken as string;
}

// ---------------------------------------------------------------------------
// Каталог і слоти
// ---------------------------------------------------------------------------

/** Філії-пісочниці Харкова з каталогу постачальника (з рампами і лімітами). */
export async function sandboxStores(
  ctx: APIRequestContext,
  supplierToken: string,
): Promise<SandboxStore[]> {
  const res = await ctx.get(
    `${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`,
    { headers: { Authorization: `Bearer ${supplierToken}` } },
  );
  expect(res.ok(), `каталог філій Харкова: ${res.status()}`).toBeTruthy();
  const items = (await res.json()).items as SandboxStore[];
  const sandbox = items.filter((s) => SANDBOX_EXTERNAL_IDS.includes(s.externalId as never));
  expect(
    sandbox.length,
    `у пісочниці мають бути філії ${SANDBOX_EXTERNAL_IDS.join(', ')}`,
  ).toBeGreaterThan(0);
  return sandbox;
}

/**
 * Цілодобова філія-пісочниця для перевірок, які доводять бронювання до
 * «На місці» (див. ARRIVAL_SANDBOX_EXTERNAL_IDS у env.ts).
 *
 * Домен приймає відмітку лише у вікні «slotStart − 60 хв … кінець слоту»
 * (розділ 8). У звичайної філії прийом денний, тож нічний прогін не міг би
 * відмітити прибуття взагалі; у цій — прийом цілодобовий і lead time 0,
 * тому найближчий слот завжди всередині вікна.
 */
export async function arrivalSandboxStore(
  ctx: APIRequestContext,
  supplierToken: string,
): Promise<SandboxStore> {
  const res = await ctx.get(
    `${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Харків')}`,
    { headers: { Authorization: `Bearer ${supplierToken}` } },
  );
  expect(res.ok(), `каталог філій Харкова: ${res.status()}`).toBeTruthy();

  const catalogue = (await res.json()).items as SandboxStore[];
  const tried: string[] = [];

  // Пробуємо цілодобові філії по черзі: беремо першу, де є вільний слот із
  // УЖЕ ВІДКРИТИМ вікном відмітки. Перевіряємо не лише наявність філії, а й
  // ту єдину властивість, заради якої вона існує — інакше падіння виглядало б
  // як загадкове «кнопки немає», а причина була б у конфігурації стенду.
  for (const externalId of ARRIVAL_SANDBOX_EXTERNAL_IDS) {
    const store = catalogue.find((s) => s.externalId === externalId);
    if (!store) {
      tried.push(`${externalId}: немає в каталозі (неактивна або невидима постачальникам)`);
      continue;
    }

    // Дивимось і на завтра: вікно відмітки відкривається за годину до слоту,
    // тож близько опівночі придатні слоти лежать уже в наступній добі, а
    // сьогоднішні всі минулі. Без цього набір не міг пройти останню годину дня.
    let ready = 0;
    for (const dateKey of [kyivDateKey(), kyivDateKey(1)]) {
      const grid = await slotGrid(ctx, supplierToken, store.storeId, dateKey);
      const now = Date.parse(grid.now) || Date.now();
      ready += grid.slots.filter(
        (s) =>
          s.state === 'available' &&
          s.selectable &&
          Date.parse(s.slotStart) - ARRIVAL_WINDOW_MINUTES * 60_000 <= now,
      ).length;
      if (ready > 0) {
        break;
      }
    }

    if (ready > 0) {
      return store;
    }
    tried.push(`${externalId}: вільних слотів із відкритим вікном немає (сьогодні й завтра)`);
  }

  throw new Error(
    'Немає цілодобової філії з вільним слотом, чиє вікно відмітки «На місці» вже відкрите. ' +
      `Перевірено: ${tried.join('; ')}. ` +
      'Як відновити або додати таку філію — див. коментар ARRIVAL_SANDBOX_EXTERNAL_IDS у support/env.ts.',
  );
}

export async function slotGrid(
  ctx: APIRequestContext,
  supplierToken: string,
  storeId: string,
  dateKey: string,
): Promise<{ slots: Slot[]; maxVehicleWeightTons: number; slotSizeMinutes: number; now: string }> {
  const res = await ctx.get(
    `${HOSTS.supplier}/api/supplier/v1/stores/${storeId}/slots?date=${dateKey}`,
    { headers: { Authorization: `Bearer ${supplierToken}` } },
  );
  expect(res.ok(), `сітка слотів ${storeId} на ${dateKey}: ${res.status()}`).toBeTruthy();
  return await res.json();
}

// ---------------------------------------------------------------------------
// Створення бронювань
// ---------------------------------------------------------------------------

export interface MadeBooking extends Booking {
  readonly store: SandboxStore;
}

/**
 * Створює бронювання постачальника на вільному слоті однієї з філій-пісочниць.
 * Перебирає філії, доки не знайде вільний слот на потрібну дату.
 */
export async function createBooking(
  ctx: APIRequestContext,
  supplierToken: string,
  stores: readonly SandboxStore[],
  options: {
    label: string;
    dateKey?: string;
    palletsCount?: number;
    /** Пропустити перші N вільних слотів — щоб різні тести не билися за один. */
    skipSlots?: number;
    /** Взяти слот на конкретній рампі. */
    rampId?: string;
    /** Взяти слот, що починається саме в цей момент (ISO UTC). */
    slotStart?: string;
  },
): Promise<MadeBooking> {
  const dateKey = options.dateKey ?? kyivDateKey();
  const pallets = options.palletsCount ?? 12;
  const attempted: string[] = [];

  for (const store of stores) {
    const grid = await slotGrid(ctx, supplierToken, store.storeId, dateKey);
    const free = grid.slots
      .filter((s) => s.state === 'available' && s.selectable)
      .filter((s) => (options.rampId ? s.rampId === options.rampId : true))
      .filter((s) => (options.slotStart ? s.slotStart === options.slotStart : true))
      .sort((a, b) => a.slotStart.localeCompare(b.slotStart));
    attempted.push(`${store.externalId}: вільних ${free.length}`);

    for (const slot of free.slice(options.skipSlots ?? 0)) {
      const key = { storeId: store.storeId, rampId: slot.rampId, slotStart: slot.slotStart };
      const hold = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/slots/hold`, {
        headers: { Authorization: `Bearer ${supplierToken}` },
        data: key,
      });
      if (hold.status() !== 201) continue; // слот перехопили — беремо наступний
      const holdToken = (await hold.json()).holdToken as string;

      const orderId = testOrderId(options.label);
      const plateNumber = testPlate();
      const created = await ctx.post(`${HOSTS.supplier}/api/supplier/v1/bookings`, {
        headers: { Authorization: `Bearer ${supplierToken}` },
        data: {
          ...key,
          holdToken,
          palletsCount: pallets,
          orderId,
          vehicle: {
            plateNumber,
            weightTons: Math.min(10, store.maxVehicleWeightTons),
            brand: 'UITEST',
          },
        },
      });
      if (created.status() !== 201 && created.status() !== 200) {
        // Холд не можна лишати висіти: інакше слот блокується до кінця TTL
        // і наступні перевірки бачать штучно зайняту сітку.
        await ctx
          .delete(`${HOSTS.supplier}/api/supplier/v1/slots/hold`, {
            headers: { Authorization: `Bearer ${supplierToken}` },
            data: { ...key, holdToken },
          })
          .catch(() => undefined);
        continue;
      }
      const booking = (await created.json()) as Booking;
      registerArtifact('booking', booking.id, `${orderId} · ${plateNumber} · філія ${store.externalId}`);
      return { ...booking, store };
    }
  }

  throw new Error(
    `Не вдалося створити бронювання на ${dateKey}: немає вільних слотів у пісочниці (${attempted.join('; ')})`,
  );
}

/** Читання бронювання очима постачальника — щоб звірити стан після дій магазину. */
export async function readBooking(
  ctx: APIRequestContext,
  supplierToken: string,
  bookingId: string,
): Promise<Booking> {
  const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/bookings/${bookingId}`, {
    headers: { Authorization: `Bearer ${supplierToken}` },
  });
  expect(res.ok(), `читання бронювання ${bookingId}: ${res.status()}`).toBeTruthy();
  return (await res.json()) as Booking;
}

/** Прибирання: скасувати бронювання, поки воно ще в статусі booked. */
export async function cancelBooking(
  ctx: APIRequestContext,
  supplierToken: string,
  bookingId: string,
): Promise<void> {
  await ctx
    .delete(`${HOSTS.supplier}/api/supplier/v1/bookings/${bookingId}`, {
      headers: { Authorization: `Bearer ${supplierToken}` },
      data: { reason: 'UITEST cleanup' },
    })
    .catch(() => undefined);
}

/**
 * Дія магазину через API — ТІЛЬКИ для підготовки стану (наприклад, щоб
 * отримати бронювання в статусі `unloading`). Самі дії перевіряються в UI.
 */
export async function storeAction(
  ctx: APIRequestContext,
  storeToken: string,
  bookingId: string,
  action: string,
  body: Record<string, unknown> = {},
): Promise<{ status: number; body: unknown }> {
  const res = await ctx.post(`${HOSTS.store}/api/store/v1/bookings/${bookingId}/${action}`, {
    headers: { Authorization: `Bearer ${storeToken}` },
    data: body,
  });
  return { status: res.status(), body: await res.json().catch(() => ({})) };
}
