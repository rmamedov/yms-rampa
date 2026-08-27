import { TestBed } from '@angular/core/testing';
import { NavigatorPreferenceService } from './navigator-preference.service';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';
import type { RouteDestination } from '../util/deep-links';

const store: RouteDestination = {
  city: 'Київ',
  address: 'просп. Володимира Івасюка, 46',
};

describe('NavigatorPreferenceService (DRV-22, NAV-04)', () => {
  let service: NavigatorPreferenceService;
  let opened: string[];

  beforeEach(() => {
    localStorage.clear();
    opened = [];
    TestBed.configureTestingModule({});
    service = TestBed.inject(NavigatorPreferenceService);
    jest
      .spyOn(service, 'openExternal')
      .mockImplementation((url: string) => void opened.push(url));
  });

  it('спершу вподобання немає — показується вибір', () => {
    expect(service.preferred()).toBeNull();
  });

  it('запамʼятовує обраний навігатор у localStorage', () => {
    service.openRoute('waze', store);

    expect(service.preferred()).toBe('waze');
    expect(TestBed.inject(LocalStorageService).getRaw(STORAGE_KEYS.navigatorApp)).toBe(
      'waze',
    );
    expect(opened).toEqual([
      'https://waze.com/ul?q=' +
        encodeURIComponent('Київ, просп. Володимира Івасюка, 46') +
        '&navigate=yes',
    ]);
  });

  it('вибір переживає перестворення сервісу (між сеансами)', () => {
    service.set('google');

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    expect(TestBed.inject(NavigatorPreferenceService).preferred()).toBe('google');
  });

  it('«Змінити навігатор» скидає вподобання', () => {
    service.set('google');
    service.reset();

    expect(service.preferred()).toBeNull();
  });

  it('можна відкрити маршрут без запамʼятовування вибору', () => {
    service.openRoute('google', store, false);

    expect(service.preferred()).toBeNull();
    expect(opened[0]).toContain('travelmode=driving');
  });
});
