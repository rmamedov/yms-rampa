/**
 * Помічники тестів кабінету постачальника.
 *
 * Правило набору: очікуване значення береться з API стенду, а не з голови
 * автора. Тому тут є повноцінний API-клієнт постачальника — тести питають
 * бекенд «скільки насправді міст / філій / слотів» і звіряють з екраном.
 *
 * Друга особливість: на стенді паралельно працюють інші перевірки, тож
 * списки бронювань читаються ДВІЧІ (до і після зчитування екрана), і
 * порівняння йде з перетином/обʼєднанням — див. stableSet().
 */
import { APIRequestContext, Locator, Page, expect, request } from '@playwright/test';
import { CREDS, HOSTS, registerArtifact } from './env';

export const SUPPLIER_API = `${HOSTS.supplier}/api/supplier/v1`;

/** Термін життя accessToken — 900 с; оновлюємо із запасом. */
const TOKEN_MAX_AGE_MS = 600_000;

// ─────────────────────────────── тестові дані ───────────────────────────────

let seq = 0;

/** Унікальна 4-значна мітка: держномер UT<мітка>XX, телефон +38099000<мітка>. */
export function stamp(): string {
  seq += 1;
  const base = Math.floor(Math.random() * 9000) + 1000;
  return String(((base + seq * 7) % 9000) + 1000);
}

export function uitestPlate(mark: string): string {
  return `UT${mark}XX`;
}

/** Телефони тестових водіїв — рівно діапазон +38099000XXXX із плану. */
export function uitestPhone(mark: string): string {
  return `+38099000${mark}`;
}

export function uitestOrderId(mark: string): string {
  return `UITEST-${mark}`;
}

// ──────────────────────────────── дати (Київ) ────────────────────────────────

export function kyivToday(): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Kyiv' }).format(new Date());
}

export function shiftDate(dateIso: string, days: number): string {
  const base = new Date(`${dateIso}T12:00:00Z`);
  base.setUTCDate(base.getUTCDate() + days);
  return base.toISOString().slice(0, 10);
}

export function diffDays(fromIso: string, toIso: string): number {
  const a = Date.parse(`${fromIso}T12:00:00Z`);
  const b = Date.parse(`${toIso}T12:00:00Z`);
  return Math.round((b - a) / 86_400_000);
}

/** Неділя — вихідний у всіх філіях стенду, сітка на неї порожня. */
export function isSunday(dateIso: string): boolean {
  return new Date(`${dateIso}T12:00:00Z`).getUTCDay() === 0;
}

/** Найближчий робочий день не раніше ніж через `minDaysAhead` днів. */
export function workingDay(minDaysAhead = 1): string {
  let date = shiftDate(kyivToday(), minDaysAhead);
  while (isSunday(date)) {
    date = shiftDate(date, 1);
  }
  return date;
}

export function nearestSunday(minDaysAhead = 1): string {
  let date = shiftDate(kyivToday(), minDaysAhead);
  while (!isSunday(date)) {
    date = shiftDate(date, 1);
  }
  return date;
}

// ───────────────────────────────── API стенду ────────────────────────────────

export interface CityDto {
  city: string;
  storeCount: number;
}

export interface RampDto {
  rampId: string;
  number: number;
  name: string;
}

export interface StoreDto {
  storeId: string;
  externalId: string;
  name: string;
  city: string;
  address: string;
  ramps: RampDto[];
  maxVehicleWeightTons: number;
  slotSizeMinutes: number;
  leadTimeMinutes: number;
  bookingHorizonDays: number;
}

export interface SlotDto {
  rampId: string;
  slotStart: string;
  slotEnd: string;
  localStart: string;
  state: 'available' | 'held' | 'booked' | 'reserved' | 'blocked' | 'past';
  selectable: boolean;
  reservedForYou?: boolean;
}

export interface SlotGridDto {
  storeId: string;
  date: string;
  maxVehicleWeightTons: number;
  slotSizeMinutes: number;
  leadTimeMinutes: number;
  now: string;
  slots: SlotDto[];
}

export interface VehicleDto {
  id: string;
  plateNumber: string;
  brand: string | null;
  weightTons: number;
  active: boolean;
}

export interface DriverDto {
  id: string;
  phone: string;
  firstName: string;
  lastName: string;
  defaultVehicleId: string | null;
  active: boolean;
}

export interface RouteSheetPointDto {
  bookingId: string;
  city: string;
  storeName: string;
  address: string;
  localTime: string;
  slotStart: string;
  rampId: string;
  orderId: string | null;
  palletsCount: number;
  plateNumber: string;
  driverId: string | null;
  status: string;
}

export interface RouteSheetDto {
  routeSheetId: string;
  supplierId: string;
  supplierName: string | null;
  date: string;
  printVersion: number;
  points: RouteSheetPointDto[];
}

/** API-клієнт постачальника з автоматичним оновленням токена. */
export class Sup {
  private takenPlates: Set<string> | null = null;
  private takenPhones: Set<string> | null = null;

  private constructor(
    private readonly ctx: APIRequestContext,
    private token: string,
    private issuedAt: number,
  ) {}

  static async open(): Promise<Sup> {
    const ctx = await request.newContext({ ignoreHTTPSErrors: true });
    const token = await Sup.login(ctx);
    return new Sup(ctx, token, Date.now());
  }

  private static async login(ctx: APIRequestContext): Promise<string> {
    const res = await ctx.post(`${SUPPLIER_API}/auth/login`, {
      data: { login: CREDS.supplier.login, password: CREDS.supplier.password },
    });
    if (!res.ok()) {
      throw new Error(`Вхід постачальника в API не вдався: ${res.status()} ${await res.text()}`);
    }
    return (await res.json()).accessToken as string;
  }

  private async headers(): Promise<Record<string, string>> {
    if (Date.now() - this.issuedAt > TOKEN_MAX_AGE_MS) {
      this.token = await Sup.login(this.ctx);
      this.issuedAt = Date.now();
    }
    return { Authorization: `Bearer ${this.token}` };
  }

  async get<T>(path: string, params?: Record<string, string | number | boolean>): Promise<T> {
    const res = await this.ctx.get(`${SUPPLIER_API}${path}`, { headers: await this.headers(), params });
    if (!res.ok()) {
      throw new Error(`GET ${path} → ${res.status()} ${await res.text()}`);
    }
    return (await res.json()) as T;
  }

  /** Сирий запит — коли треба перевірити саме код помилки. */
  async raw(method: 'get' | 'post' | 'patch' | 'delete', path: string, data?: unknown) {
    return this.ctx.fetch(`${SUPPLIER_API}${path}`, {
      method: method.toUpperCase(),
      headers: { ...(await this.headers()), 'Content-Type': 'application/json' },
      data: data ?? undefined,
    });
  }

  /**
   * Вільний держномер із діапазону UT<4 цифри>XX. Пул спільний з іншими
   * прогонами і вже частково зайнятий, тому беремо той, якого ще немає в
   * довіднику: інакше тест падав би на 409 замість перевірки сценарію.
   */
  async freePlate(): Promise<string> {
    if (!this.takenPlates) {
      this.takenPlates = new Set((await this.vehicles()).map((v) => v.plateNumber));
    }
    for (let i = 0; i < 10_000; i++) {
      const plate = uitestPlate(String(Math.floor(Math.random() * 9000) + 1000));
      if (!this.takenPlates.has(plate)) {
        this.takenPlates.add(plate);
        return plate;
      }
    }
    throw new Error('вільних тестових держномерів UT****XX не лишилось');
  }

  /** Вільний телефон із діапазону +38099000XXXX. */
  async freePhone(): Promise<string> {
    if (!this.takenPhones) {
      this.takenPhones = new Set((await this.drivers()).map((d) => d.phone));
    }
    for (let i = 0; i < 10_000; i++) {
      const phone = uitestPhone(String(Math.floor(Math.random() * 9000) + 1000));
      if (!this.takenPhones.has(phone)) {
        this.takenPhones.add(phone);
        return phone;
      }
    }
    throw new Error('вільних тестових телефонів +38099000XXXX не лишилось');
  }

  async cities(): Promise<CityDto[]> {
    return (await this.get<{ items: CityDto[] }>('/cities')).items;
  }

  async stores(city: string): Promise<{ items: StoreDto[]; total: number }> {
    return this.get('/stores', { city, perPage: 100 });
  }

  async store(storeId: string): Promise<StoreDto> {
    return this.get(`/stores/${storeId}`);
  }

  async slots(storeId: string, date: string): Promise<SlotGridDto> {
    return this.get(`/stores/${storeId}/slots`, { date });
  }

  async vehicles(): Promise<VehicleDto[]> {
    return (await this.get<{ items: VehicleDto[] }>('/vehicles', { includeInactive: true })).items;
  }

  async drivers(): Promise<DriverDto[]> {
    return (await this.get<{ items: DriverDto[] }>('/drivers')).items;
  }

  async sheet(date: string): Promise<RouteSheetDto> {
    return this.get('/route-sheets', { date });
  }

  /** Готове бронювання для сценаріїв, де сам процес бронювання не перевіряється. */
  async createBooking(input: {
    storeId: string;
    rampId: string;
    slotStart: string;
    plateNumber: string;
    weightTons: number;
    palletsCount: number;
    orderId: string;
    driverId?: string | null;
  }): Promise<{ id: string; localTime: string; localDate: string }> {
    const res = await this.raw('post', '/bookings', {
      storeId: input.storeId,
      rampId: input.rampId,
      slotStart: input.slotStart,
      vehicle: { plateNumber: input.plateNumber, weightTons: input.weightTons, brand: 'UITEST' },
      palletsCount: input.palletsCount,
      orderId: input.orderId,
      driverId: input.driverId ?? null,
      holdToken: null,
      confirmConflict: true,
    });
    if (!res.ok()) {
      throw new Error(`Створення бронювання не вдалося: ${res.status()} ${await res.text()}`);
    }
    const booking = await res.json();
    registerArtifact('booking', booking.id, `${input.orderId} ${input.slotStart}`);
    return booking;
  }

  /** Авто в довіднику — для сценаріїв, де сам довідник не перевіряється. */
  async createVehicle(input: {
    plateNumber: string;
    weightTons: number;
    brand?: string;
  }): Promise<VehicleDto> {
    const res = await this.raw('post', '/vehicles', {
      plateNumber: input.plateNumber,
      weightTons: input.weightTons,
      brand: input.brand ?? 'UITEST',
    });
    if (!res.ok()) {
      throw new Error(`Створення авто не вдалося: ${res.status()} ${await res.text()}`);
    }
    const vehicle = (await res.json()) as VehicleDto;
    registerArtifact('vehicle', vehicle.id, vehicle.plateNumber);
    return vehicle;
  }

  /** Водій для сценаріїв, де сам довідник водіїв не перевіряється. */
  async createDriver(input: { phone: string; firstName: string; lastName: string }): Promise<DriverDto> {
    const res = await this.raw('post', '/drivers', { ...input, defaultVehicleId: null });
    if (!res.ok()) {
      throw new Error(`Створення водія не вдалося: ${res.status()} ${await res.text()}`);
    }
    const driver = (await res.json()) as DriverDto;
    registerArtifact('driver', driver.id, input.phone);
    return driver;
  }

  async assignSheetDriver(date: string, driverId: string): Promise<void> {
    const res = await this.raw('post', '/route-sheets/driver', { date, driverId });
    if (!res.ok()) {
      throw new Error(`Призначення водія на лист не вдалося: ${res.status()} ${await res.text()}`);
    }
  }

  async cancelBooking(bookingId: string, reason = 'UITEST cleanup'): Promise<void> {
    const res = await this.raw('delete', `/bookings/${bookingId}`, { reason });
    if (!res.ok() && res.status() !== 404) {
      throw new Error(`Скасування ${bookingId} не вдалося: ${res.status()} ${await res.text()}`);
    }
  }

  async deleteVehicle(vehicleId: string): Promise<void> {
    await this.raw('delete', `/vehicles/${vehicleId}`);
  }

  /**
   * Прибирання довідника: видалення на стенді відхиляється завжди
   * (partner-service тримає fail-closed заглушку порту бронювань),
   * тому запасний варіант — деактивація.
   */
  async releaseVehicle(vehicleId: string): Promise<void> {
    const res = await this.raw('delete', `/vehicles/${vehicleId}`);
    if (!res.ok()) {
      await this.raw('post', `/vehicles/${vehicleId}/deactivate`);
    }
  }

  async deactivateDriver(driverId: string): Promise<void> {
    await this.raw('post', `/drivers/${driverId}/deactivate`);
  }

  async dispose(): Promise<void> {
    await this.ctx.dispose();
  }
}

/**
 * Стабільний зріз даних, що змінюються паралельними перевірками.
 * `must` — те, що існувало і до, і після зчитування екрана (мусить бути на
 * екрані), `may` — обʼєднання (нічого поза ним на екрані бути не може).
 */
export function stableSet<T>(before: T[], after: T[], key: (item: T) => string) {
  const beforeKeys = new Set(before.map(key));
  const afterKeys = new Set(after.map(key));
  return {
    must: before.filter((item) => afterKeys.has(key(item))),
    may: [...new Map([...before, ...after].map((item) => [key(item), item])).values()],
  };
}

// ────────────────────────────────── UI ───────────────────────────────────────

export async function loginSupplier(page: Page): Promise<void> {
  await page.goto(HOSTS.supplier + '/');
  await page.waitForSelector('#login-password', { timeout: 30_000 });
  await page.locator('#login-email').fill(CREDS.supplier.login);
  await page.locator('#login-password').fill(CREDS.supplier.password);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/auth/login') && r.request().method() === 'POST', {
      timeout: 30_000,
    }),
    page.locator('button[type=submit]').click(),
  ]);
  await page.waitForFunction(() => !location.pathname.includes('/login'), undefined, { timeout: 30_000 });
  await page.waitForLoadState('networkidle');
}

export async function goto(page: Page, path: string): Promise<void> {
  await page.goto(HOSTS.supplier + path);
  await page.waitForLoadState('networkidle');
}

/**
 * Тости живуть 6 секунд і накладаються один на одного, тому шукати треба
 * саме потрібний текст, а не «перший тост».
 */
export function toast(page: Page, text: string | RegExp) {
  return page.locator('.toast__text').filter({ hasText: text });
}

export function normalizedText(raw: string): string {
  return raw.replace(/\s+/g, ' ').trim();
}

export async function bodyText(page: Page): Promise<string> {
  return normalizedText(await page.locator('body').innerText());
}

/** Клік по даті у стрічці сітки слотів (індекс = зсув від сьогодні). */
export async function selectGridDate(page: Page, dateIso: string): Promise<void> {
  const index = diffDays(kyivToday(), dateIso);
  expect(index, 'дата має бути у видимій стрічці з 7 днів').toBeGreaterThanOrEqual(0);
  expect(index).toBeLessThan(7);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/slots?') && r.url().includes(dateIso), { timeout: 20_000 }),
    page.locator('.dates .date').nth(index).click(),
  ]);
  await expect(page.locator('.date--active')).toHaveCount(1);
}

/** Клітинка сітки за часом рядка і номером колонки (рампи). */
export function cellAt(page: Page, time: string, column: number): Locator {
  return page
    .locator('.slot-grid tbody tr')
    .filter({ has: page.locator(`th:text-is("${time}")`) })
    .locator('.slot-grid__cell')
    .nth(column);
}

// ───────────────────────── наскрізна перевірка мови X-07 ─────────────────────

/** Ключі перекладу, що просочилися в інтерфейс, і англійські слова. */
const ENGLISH_WORDS =
  /\b(Loading|Error|Submit|Cancel|Save|Search|Delete|Edit|Undefined|undefined|null|NaN|Invalid|Required|Not found)\b/g;

export function languageProblems(text: string): string[] {
  const found: string[] = [];
  const keyLike = text.match(/\b[a-z][a-z0-9]*(\.[a-z][a-zA-Z0-9]*){2,}\b/g);
  if (keyLike) {
    found.push(...new Set(keyLike));
  }
  const english = text.match(ENGLISH_WORDS);
  if (english) {
    found.push(...new Set(english));
  }
  return found;
}

/** Скільки пікселів сторінка «вилазить» за ширину екрана (X-10). */
export async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}
