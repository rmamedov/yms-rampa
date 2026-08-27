import { googleMapsUrl, hasCoordinates, navigatorUrl, wazeUrl } from './deep-links';
import type { StoreRef } from '../models/route-sheet.model';

const store: StoreRef = {
  storeId: 'st-1998',
  externalId: '1998',
  name: 'Сільпо №1998',
  city: 'Київ',
  address: 'просп. Володимира Івасюка, 46',
  latitude: 50.52022,
  longitude: 30.51452,
};

const noCoords: StoreRef = { ...store, latitude: null, longitude: null };

describe('диплінки навігаторів (DRV-21, NAV-02)', () => {
  it('Google Maps використовує точний формат з координатами', () => {
    expect(googleMapsUrl(store)).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=50.52022,30.51452&travelmode=driving',
    );
  });

  it('Waze використовує точний формат з координатами', () => {
    expect(wazeUrl(store)).toBe(
      'https://waze.com/ul?ll=50.52022,30.51452&navigate=yes',
    );
  });

  it('без координат передається URL-encoded адреса', () => {
    const url = googleMapsUrl(noCoords);
    expect(url).toContain('destination=' + encodeURIComponent('Київ, просп. Володимира Івасюка, 46'));
    expect(url).not.toContain('null');
  });

  it('використовуються лише universal links https (NAV-03)', () => {
    expect(navigatorUrl('google', store).startsWith('https://')).toBe(true);
    expect(navigatorUrl('waze', store).startsWith('https://')).toBe(true);
  });

  it('hasCoordinates розпізнає дефект даних MCP (DRV-23)', () => {
    expect(hasCoordinates(store)).toBe(true);
    expect(hasCoordinates(noCoords)).toBe(false);
    expect(hasCoordinates({ ...store, latitude: Number.NaN })).toBe(false);
  });
});
