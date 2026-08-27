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
});
