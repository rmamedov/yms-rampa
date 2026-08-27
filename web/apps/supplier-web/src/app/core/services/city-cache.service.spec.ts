import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CatalogApi } from '../api/contracts';
import type {
  BranchDetail,
  BranchItem,
  CityItem,
  SlotGrid,
} from '../models/models';
import { CityCacheService, CITY_CACHE_TTL_MS } from './city-cache.service';

class StubCatalogApi extends CatalogApi {
  calls = 0;

  override cities(): Observable<CityItem[]> {
    this.calls++;
    return of([{ city: 'Київ', storeCount: 118 }]);
  }

  override branches(): Observable<BranchItem[]> {
    return of([]);
  }

  override branch(): Observable<BranchDetail> {
    return of({} as BranchDetail);
  }

  override slots(): Observable<SlotGrid> {
    return of({} as SlotGrid);
  }
}

describe('CityCacheService (SUP-CITY-04)', () => {
  let api: StubCatalogApi;
  let service: CityCacheService;

  beforeEach(() => {
    api = new StubCatalogApi();
    TestBed.configureTestingModule({
      providers: [{ provide: CatalogApi, useValue: api }],
    });
    service = TestBed.inject(CityCacheService);
  });

  it('кешує список міст і не звертається до API повторно', () => {
    service.cities().subscribe();
    service.cities().subscribe();
    expect(api.calls).toBe(1);
  });

  it('оновлює кеш після спливання 10 хвилин', () => {
    const start = Date.now();
    const spy = jest.spyOn(Date, 'now').mockReturnValue(start);
    service.cities().subscribe();

    spy.mockReturnValue(start + CITY_CACHE_TTL_MS + 1000);
    service.cities().subscribe();
    expect(api.calls).toBe(2);
    spy.mockRestore();
  });

  it('скидає кеш за запитом', () => {
    service.cities().subscribe();
    service.invalidate();
    service.cities().subscribe();
    expect(api.calls).toBe(2);
  });
});
