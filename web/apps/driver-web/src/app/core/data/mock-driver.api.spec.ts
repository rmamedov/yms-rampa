import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { DriverApi } from './driver.api';
import { MockDriverApi } from './mock-driver.api';
import { NetworkService } from '../offline/network.service';
import { ApiProblemError } from '../models/problem.model';
import { addDaysToDateKey, kyivDateKey } from '../util/time.util';

/**
 * Режим розробки (environment.useMocks) має лишатися робочим і давати
 * ТУ САМУ форму даних, що й HttpDriverApi.
 */
describe('MockDriverApi', () => {
  let api: DriverApi;
  let network: NetworkService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [{ provide: DriverApi, useClass: MockDriverApi }],
    });
    api = TestBed.inject(DriverApi);
    network = TestBed.inject(NetworkService);
    network.setOnline(true);
  });

  it('віддає денний зріз на сьогодні', async () => {
    const sheet = await firstValueFrom(api.routeSheet(kyivDateKey()));

    expect(sheet?.date).toBe(kyivDateKey());
    expect(sheet?.points.length).toBeGreaterThan(0);
    expect(sheet?.routeSheetIds).toHaveLength(1);
  });

  it('дата без поїздок дає null, а не помилку', async () => {
    const sheet = await firstValueFrom(
      api.routeSheet(addDaysToDateKey(kyivDateKey(), 30)),
    );

    expect(sheet).toBeNull();
  });

  it('перелік дат покриває сьогодні + горизонт', async () => {
    const dates = await firstValueFrom(api.availableDates());

    expect(dates.map((d) => d.date)).toEqual([
      kyivDateKey(),
      addDaysToDateKey(kyivDateKey(), 1),
      addDaysToDateKey(kyivDateKey(), 2),
    ]);
    expect(dates.every((d) => d.pointCount > 0)).toBe(true);
  });

  it('в офлайні падає так само, як HTTP', async () => {
    network.setOnline(false);

    await expect(
      firstValueFrom(api.routeSheet(kyivDateKey())),
    ).rejects.toBeInstanceOf(ApiProblemError);
  });

  describe('дії водія', () => {
    /** Перша точка листа у статусі booked. */
    const bookedPoint = async () => {
      const sheet = await firstValueFrom(api.routeSheet(kyivDateKey()));
      const point = sheet?.points.find((p) => p.status === 'booked');
      if (!point) {
        throw new Error('у моці немає точки зі статусом booked');
      }
      return point;
    };

    it('«На місці» повертає arrived, повторний виклик — теж (ідемпотентність)', async () => {
      const point = await bookedPoint();

      const first = await firstValueFrom(
        api.markArrived(point.bookingId, '2026-08-27T09:00:00Z'),
      );
      const second = await firstValueFrom(
        api.markArrived(point.bookingId, '2026-08-27T09:05:00Z'),
      );

      expect(first.status).toBe('arrived');
      expect(second.status).toBe('arrived');
      expect(second.arrivedAt).toBe(first.arrivedAt);
    });

    it('затримка з ETA в минулому падає з 422, як на бекенді', async () => {
      const point = await bookedPoint();

      await expect(
        firstValueFrom(
          api.reportDelay(point.bookingId, {
            reason: 'затори',
            eta: new Date(Date.now() - 60_000).toISOString(),
          }),
        ),
      ).rejects.toMatchObject({ status: 422, message: 'ETA має бути в майбутньому' });
    });

    it('затримка з коректним ETA піднімає прапорець delayed без зміни статусу', async () => {
      const point = await bookedPoint();

      const result = await firstValueFrom(
        api.reportDelay(point.bookingId, {
          reason: 'поломка',
          eta: new Date(Date.now() + 45 * 60_000).toISOString(),
        }),
      );

      expect(result.delayed.flag).toBe(true);
      expect(result.delayed.reason).toBe('поломка');
      expect(result.status).toBe('booked');
    });

    it('orderId дописується до розвантаження', async () => {
      const point = await bookedPoint();

      const result = await firstValueFrom(
        api.updateOrderId(point.bookingId, '4410999'),
      );

      expect(result.orderId).toBe('4410999');
    });

    it('в офлайні дії падають мережевою помилкою, а не тихо', async () => {
      const point = await bookedPoint();
      network.setOnline(false);

      await expect(
        firstValueFrom(api.markArrived(point.bookingId, '2026-08-27T09:00:00Z')),
      ).rejects.toMatchObject({ status: 0, code: 'NETWORK_UNAVAILABLE' });
    });
  });
});
