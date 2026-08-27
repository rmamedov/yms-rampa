import { I18nService, interpolate } from './i18n.service';

describe('I18nService', () => {
  const i18n = new I18nService();

  it('перекладає ключі українською', () => {
    expect(i18n.t('login.invalid')).toBe('Невірний логін або пароль');
    expect(i18n.t('slots.state.held')).toBe('Оформлюється');
  });

  it('підставляє параметри у шаблон', () => {
    expect(i18n.t('slots.maxWeight', { tons: 20 })).toBe(
      'Приймаємо авто до 20 т',
    );
    expect(interpolate('{a} + {b}', { a: 1, b: 2 })).toBe('1 + 2');
    expect(interpolate('{a} + {missing}', { a: 1 })).toBe('1 + {missing}');
  });

  it('повертає сам ключ, якщо перекладу немає', () => {
    expect(i18n.t('немає.такого.ключа')).toBe('немає.такого.ключа');
    expect(i18n.has('немає.такого.ключа')).toBe(false);
    expect(i18n.has('error.SLOT_ALREADY_BOOKED')).toBe(true);
  });

  it('обирає українську форму множини', () => {
    expect(i18n.storeCount(1)).toBe('1 магазин');
    expect(i18n.storeCount(3)).toBe('3 магазини');
    expect(i18n.storeCount(5)).toBe('5 магазинів');
    expect(i18n.storeCount(11)).toBe('11 магазинів');
    expect(i18n.storeCount(21)).toBe('21 магазин');
    expect(i18n.storeCount(118)).toBe('118 магазинів');
    expect(i18n.pointsCount(1)).toBe('1 точка');
    expect(i18n.pointsCount(2)).toBe('2 точки');
    expect(i18n.pointsCount(7)).toBe('7 точок');
  });

  it('має переклад для кожного відомого коду помилки', () => {
    for (const code of [
      'SLOT_ALREADY_BOOKED',
      'SLOT_HELD',
      'VEHICLE_TOO_HEAVY',
      'DATE_OUT_OF_HORIZON',
      'BOOKING_LIMIT_EXCEEDED',
      'HOLD_EXPIRED',
    ]) {
      expect(i18n.has(`error.${code}`)).toBe(true);
    }
  });
});
