import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { HttpStoresApi } from './http-apis';
import { environment } from '../../../../environments/environment';

/**
 * Контракт запитів картки магазину.
 *
 * Перевіряється саме АДРЕСА, бо тут жив дефект: картка читала
 * /configurations/current — чинну сьогодні версію. Нова версія за STC-60
 * набирає чинності не раніше завтра, тож одразу після збереження екран
 * перемальовувався старими даними, і щойно введене вікно прийому зникало.
 * Виглядало це як «розклад на неділю не зберігається».
 */
describe('HttpStoresApi — джерело конфігурації для картки', () => {
  let api: HttpStoresApi;
  let http: HttpTestingController;

  const base = environment.apiBaseUrl;
  const storeId = '1edb6b19-975d-6c30-b998-a3127616e373';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), HttpStoresApi],
    });
    api = TestBed.inject(HttpStoresApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('картка читає ОСТАННЮ версію, а не чинну сьогодні', () => {
    api.get(storeId).subscribe();

    http.expectOne(`${base}/stores/${storeId}/configurations/latest`);
    http.expectNone(`${base}/stores/${storeId}/configurations/current`);

    // Решта запитів картки нас тут не цікавить — але без відповіді на них
    // HttpTestingController поскаржиться на незакриті звернення.
    http.expectOne(`${base}/stores/${storeId}`);
    http.expectOne(`${base}/stores/${storeId}/reserved-slot-rules`);
    http.expectOne(`${base}/stores/${storeId}/slot-blocks`);
  });

  it('ненастроєний магазин (404 на конфігурації) не ламає картку', () => {
    let failed: unknown = null;
    api.get(storeId).subscribe({ error: (e: unknown) => (failed = e) });

    http
      .expectOne(`${base}/stores/${storeId}/configurations/latest`)
      .flush(
        { type: 'about:blank', title: 'Not Found', status: 404, code: 'CONFIG_NOT_FOUND' },
        { status: 404, statusText: 'Not Found' },
      );
    http.expectOne(`${base}/stores/${storeId}`).flush({
      branchId: storeId,
      displayName: 'Сільпо, вул. Білоруська, 2',
      effectiveDisplayName: 'Сільпо, вул. Білоруська, 2',
      ymsStatus: 'draft',
      ymsStatusLabel: 'Не налаштовано',
      configured: false,
      missingSettings: [],
      visibleToSuppliers: false,
      eligible: true,
      mcpData: {
        branchId: storeId,
        companyId: 'c-1',
        externalId: '1995',
        city: 'Київ',
        address: 'вул. Білоруська, 2',
        latitude: 50.4621,
        longitude: 30.48126,
        hasPickup: false,
        open: true,
      },
    });
    http.expectOne(`${base}/stores/${storeId}/reserved-slot-rules`).flush({ items: [] });
    http.expectOne(`${base}/stores/${storeId}/slot-blocks`).flush({ items: [] });

    expect(failed).toBeNull();
  });
});
