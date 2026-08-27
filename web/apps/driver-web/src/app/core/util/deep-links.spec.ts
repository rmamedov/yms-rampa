import { googleMapsUrl, navigatorUrl, wazeUrl } from './deep-links';

const place = {
  city: 'Київ',
  address: 'просп. Володимира Івасюка, 46',
};

describe('диплінки навігаторів (DRV-21, NAV-02)', () => {
  it('Google Maps веде на адресу — координат бекенд не віддає', () => {
    expect(googleMapsUrl(place)).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=' +
        encodeURIComponent('Київ, просп. Володимира Івасюка, 46') +
        '&travelmode=driving',
    );
  });

  it('Waze шукає за тим самим рядком «місто, адреса»', () => {
    expect(wazeUrl(place)).toBe(
      'https://waze.com/ul?q=' +
        encodeURIComponent('Київ, просп. Володимира Івасюка, 46') +
        '&navigate=yes',
    );
  });

  it('порожнє місто не лишає висячої коми', () => {
    expect(googleMapsUrl({ city: '', address: 'вул. Тестова, 1' })).toContain(
      encodeURIComponent('вул. Тестова, 1'),
    );
    expect(googleMapsUrl({ city: '', address: 'вул. Тестова, 1' })).not.toContain(
      '%2C%20%D0%B2', // ', в' — кома перед адресою
    );
  });

  it('використовуються лише universal links https (NAV-03)', () => {
    expect(navigatorUrl('google', place).startsWith('https://')).toBe(true);
    expect(navigatorUrl('waze', place).startsWith('https://')).toBe(true);
  });
});
