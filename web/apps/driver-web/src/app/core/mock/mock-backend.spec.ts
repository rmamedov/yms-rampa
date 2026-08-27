import { TestBed } from '@angular/core/testing';
import { MockBackend } from './mock-backend';
import { ApiProblemError } from '../models/problem.model';
import { kyivDateKey } from '../util/time.util';
import type { RoutePoint } from '../models/route-sheet.model';

describe('MockBackend — бізнес-правила без бекенду', () => {
  let backend: MockBackend;
  const now = Date.now();

  beforeEach(() => {
    TestBed.configureTestingModule({});
    backend = TestBed.inject(MockBackend);
    backend.reset(now);
  });

  function pointWithStatus(status: RoutePoint['status']): RoutePoint {
    const sheet = backend.routeSheet(kyivDateKey(now), now);
    const point = sheet?.points.find((p) => p.status === status);
    if (!point) {
      throw new Error(`Немає точки зі статусом ${status}`);
    }
    return point;
  }

  it('на сьогодні є лист, точки відсортовані за часом слоту (DRV-14)', () => {
    const sheet = backend.routeSheet(kyivDateKey(now), now);
    expect(sheet).not.toBeNull();
    const starts = sheet?.points.map((p) => p.slotStart) ?? [];
    expect(starts.length).toBeGreaterThan(1);
    expect([...starts].sort()).toEqual(starts);
  });

  it('перелік доступних дат містить лише дати з листами (DRV-13)', () => {
    const dates = backend.availableDates(now);
    expect(dates.length).toBeGreaterThanOrEqual(2);
    expect(dates.every((d) => d.pointCount > 0)).toBe(true);
    expect(dates.map((d) => d.date)).toContain(kyivDateKey(now));
  });

  it('доступ до чужого бронювання дає 404 без розкриття ресурсу (DRV-38)', () => {
    expect.assertions(2);
    try {
      backend.arrive('bk-чуже', { pressedAt: new Date(now).toISOString() }, now);
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(404);
      expect((error as ApiProblemError).code).toBe('BOOKING_NOT_FOUND');
    }
  });

  it('відмітка раніше ніж за 60 хв до слоту відхиляється (DRV-24)', () => {
    const point = backend
      .routeSheet(kyivDateKey(now), now)
      ?.points.find(
        (p) =>
          p.status === 'booked' &&
          Date.parse(p.slotStart) - now > 61 * 60_000,
      );
    expect(point).toBeDefined();
    expect.assertions(3);
    try {
      backend.arrive(
        (point as RoutePoint).bookingId,
        { pressedAt: new Date(now).toISOString() },
        now,
      );
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(422);
      expect((error as ApiProblemError).code).toBe('ARRIVAL_WINDOW_NOT_OPEN');
    }
  });

  it('успішна відмітка переводить у arrived і зберігає фактичний час натискання', () => {
    const point = pointWithStatus('booked');
    const pressedAt = new Date(Date.parse(point.slotStart) + 5 * 60_000).toISOString();

    const updated = backend.arrive(point.bookingId, { pressedAt }, now);

    expect(updated.status).toBe('arrived');
    expect(updated.arrivedAt).toBe(pressedAt);
    expect(updated.delayed).toBeNull();
  });

  it('прибуття після кінця слоту система позначає delayed з актором system (DRV-24)', () => {
    const point = pointWithStatus('booked');
    const pressedAt = new Date(Date.parse(point.slotEnd) + 60_000).toISOString();

    const updated = backend.arrive(point.bookingId, { pressedAt }, now);

    expect(updated.status).toBe('arrived');
    expect(updated.delayed?.setBy).toBe('system');
    expect(updated.delayed?.reason).toBe('Прибуття після слоту');
  });

  it('повторна відмітка неможлива — 409 без зміни стану (DRV-28)', () => {
    const point = pointWithStatus('booked');
    const pressedAt = new Date(Date.parse(point.slotStart) + 60_000).toISOString();
    backend.arrive(point.bookingId, { pressedAt }, now);

    expect.assertions(2);
    try {
      backend.arrive(point.bookingId, { pressedAt }, now);
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(409);
      expect((error as ApiProblemError).code).toBe('BOOKING_ALREADY_ARRIVED');
    }
  });

  it('відмітка для скасованої точки повертає BOOKING_CANCELLED (DRV-30)', () => {
    const point = pointWithStatus('cancelled');
    expect.assertions(1);
    try {
      backend.arrive(
        point.bookingId,
        { pressedAt: new Date(now).toISOString() },
        now,
      );
    } catch (error) {
      expect((error as ApiProblemError).code).toBe('BOOKING_CANCELLED');
    }
  });

  it('orderId зберігається з обрізкою пробілів і валідацією довжини (DRV-17)', () => {
    const point = pointWithStatus('booked');

    expect(backend.setOrderId(point.bookingId, '  4410999  ').orderId).toBe('4410999');

    expect(() => backend.setOrderId(point.bookingId, '   ')).toThrow(ApiProblemError);
    expect(() => backend.setOrderId(point.bookingId, 'x'.repeat(65))).toThrow(
      ApiProblemError,
    );
  });

  it('редагування orderId заборонене для завершеної точки (DRV-19)', () => {
    const point = pointWithStatus('completed');
    expect.assertions(1);
    try {
      backend.setOrderId(point.bookingId, '123');
    } catch (error) {
      expect((error as ApiProblemError).status).toBe(409);
    }
  });

  it('затримка приймається лише для booked і лише з ETA у майбутньому (DRV-41)', () => {
    const point = pointWithStatus('booked');
    const eta = new Date(now + 45 * 60_000).toISOString();

    const updated = backend.setDelay(point.bookingId, { eta, reason: 'Затор' }, now);
    expect(updated.delayed?.setBy).toBe('driver');
    expect(updated.delayed?.eta).toBe(eta);
    expect(updated.delayed?.reason).toBe('Затор');

    expect(() =>
      backend.setDelay(
        point.bookingId,
        { eta: new Date(now - 60_000).toISOString(), reason: 'Затор' },
        now,
      ),
    ).toThrow(ApiProblemError);

    const done = pointWithStatus('completed');
    expect(() =>
      backend.setDelay(done.bookingId, { eta, reason: 'Затор' }, now),
    ).toThrow(ApiProblemError);
  });
});
