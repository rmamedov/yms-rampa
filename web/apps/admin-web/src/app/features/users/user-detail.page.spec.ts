import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { UserDetailPage } from './user-detail.page';
import { provideDataAccess } from '../../core/data/data.providers';
import { StoresApi } from '../../core/data/stores.api';
import { MockDb } from '../../core/data/mock/mock-db';
import { MOCK_LATENCY } from '../../core/data/mock/mock-support';
import { DEFAULT_STORE_FILTER } from '../../core/utils/query-state.util';

/**
 * Клас дефектів, уже знайдений у цьому проєкті: випадний список магазинів,
 * заповнений ОДНІЄЮ сторінкою довідника. Пошук у мультиселекті працює по
 * вже завантаженому масиву, тому «хвіст» мережі стає недосяжним.
 */
describe('UserDetailPage — довідник філій для привʼязки', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        MockDb,
        ...provideDataAccess(true),
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    TestBed.inject(MockDb).reset();
  });

  it('вантажить УСІ філії мережі, а не першу сторінку', async () => {
    const stores = TestBed.inject(StoresApi);

    // Скільки філій у довіднику насправді
    const firstPage = await firstValueFrom(
      stores.list(DEFAULT_STORE_FILTER, { page: 1, pageSize: 100, sort: 'city' }),
    );
    const total = firstPage.total;

    // Довідник свідомо більший за одну сторінку — інакше тест нічого не ловить
    expect(total).toBeGreaterThan(100);

    const fixture = TestBed.createComponent(UserDetailPage);
    fixture.detectChanges();

    const options = (
      fixture.componentInstance as unknown as {
        storeOptions: () => readonly { value: string; label: string }[];
        storesLoaded: () => boolean;
      }
    ).storeOptions();

    expect(
      (
        fixture.componentInstance as unknown as { storesLoaded: () => boolean }
      ).storesLoaded(),
    ).toBe(true);
    expect(options.length).toBeGreaterThan(firstPage.items.length);

    // Записи без міста/адреси — сміття MCP, їх у списку немає; решта — усі
    const meaningful = await countMeaningfulBranches(stores, total);
    expect(options.length).toBe(meaningful);

    // Ідентифікатори унікальні — сторінки не наклалися одна на одну
    expect(new Set(options.map((o) => o.value)).size).toBe(options.length);
  });

  it('нова картка стартує як створення без привʼязаних магазинів', () => {
    const fixture = TestBed.createComponent(UserDetailPage);
    fixture.detectChanges();

    const page = fixture.componentInstance as unknown as {
      isNew: () => boolean;
      storeIds: () => readonly string[];
    };

    expect(page.isNew()).toBe(true);
    expect(page.storeIds()).toEqual([]);
  });
});

async function countMeaningfulBranches(
  stores: StoresApi,
  total: number,
): Promise<number> {
  let count = 0;
  for (let page = 1; (page - 1) * 100 < total; page += 1) {
    const chunk = await firstValueFrom(
      stores.list(DEFAULT_STORE_FILTER, { page, pageSize: 100, sort: 'city' }),
    );
    count += chunk.items.filter(
      (row) => row.city?.trim() && row.address?.trim(),
    ).length;
  }
  return count;
}
