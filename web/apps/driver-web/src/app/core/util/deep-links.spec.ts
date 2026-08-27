import {
  coordinates,
  googleMapsUrl,
  navigatorUrl,
  wazeUrl,
} from './deep-links';

/** Філія 1932 із fixtures/silpo-branches.json — з реальними координатами. */
const place = {
  city: 'Київ',
  address: 'вул. Берковецька, 6Д',
  latitude: 50.49699,
  longitude: 30.36123,
};

/** Та сама філія, але довідник координат не дав. */
const withoutCoordinates = {
  city: 'Київ',
  address: 'вул. Берковецька, 6Д',
  latitude: null,
  longitude: null,
};

describe('диплінки навігаторів (DRV-21, NAV-02)', () => {
  it('Google Maps веде на КООРДИНАТИ, а не на пошуковий рядок', () => {
    expect(googleMapsUrl(place)).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=50.49699,30.36123' +
        '&travelmode=driving',
    );
    expect(googleMapsUrl(place)).not.toContain(
      encodeURIComponent('вул. Берковецька'),
    );
  });

  it('Waze веде на координати параметром ll, а не пошуком q', () => {
    expect(wazeUrl(place)).toBe(
      'https://waze.com/ul?ll=50.49699,30.36123&navigate=yes',
    );
    expect(wazeUrl(place)).not.toContain('?q=');
  });

  it('без координат лишається запасний варіант — «місто, адреса»', () => {
    expect(googleMapsUrl(withoutCoordinates)).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=' +
        encodeURIComponent('Київ, вул. Берковецька, 6Д') +
        '&travelmode=driving',
    );
    expect(wazeUrl(withoutCoordinates)).toBe(
      'https://waze.com/ul?q=' +
        encodeURIComponent('Київ, вул. Берковецька, 6Д') +
        '&navigate=yes',
    );
  });

  it('половина координат — це не координати', () => {
    expect(coordinates({ ...place, longitude: null })).toBeNull();
    expect(coordinates({ ...place, latitude: undefined })).toBeNull();
    expect(coordinates({ ...place, latitude: Number.NaN })).toBeNull();
    // Нуль — легітимна координата, а не «порожнє значення».
    expect(coordinates({ ...place, latitude: 0, longitude: 0 })).toBe('0,0');
  });

  it('порожнє місто не лишає висячої коми', () => {
    const noCity = { city: '', address: 'вул. Тестова, 1' };

    expect(googleMapsUrl(noCity)).toContain(
      encodeURIComponent('вул. Тестова, 1'),
    );
    expect(googleMapsUrl(noCity)).not.toContain(
      '%2C%20%D0%B2', // ', в' — кома перед адресою
    );
  });

  it('використовуються лише universal links https (NAV-03)', () => {
    expect(navigatorUrl('google', place).startsWith('https://')).toBe(true);
    expect(navigatorUrl('waze', place).startsWith('https://')).toBe(true);
  });
});
