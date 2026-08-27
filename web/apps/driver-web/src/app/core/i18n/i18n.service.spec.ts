import { TestBed } from '@angular/core/testing';
import { I18nService } from './i18n.service';

describe('I18nService', () => {
  let i18n: I18nService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    i18n = TestBed.inject(I18nService);
  });

  it('повертає українські рядки за ключем', () => {
    expect(i18n.t('point.arrive')).toBe('На місці');
    expect(i18n.t('status.booked')).toBe('Очікує виїзду');
  });

  it('підставляє параметри у шаблон', () => {
    expect(i18n.t('arrive.confirmTitle', { store: 'Сільпо №1998' })).toBe(
      'Ви на місці біля магазину Сільпо №1998?',
    );
    expect(i18n.t('offline.banner', { time: '10:15' })).toBe(
      'Немає звʼязку. Показано збережений маршрут на 10:15',
    );
  });

  it('невідомий ключ повертається як є і не ламає екран', () => {
    expect(i18n.t('no.such.key')).toBe('no.such.key');
    expect(i18n.has('no.such.key')).toBe(false);
    expect(i18n.has('point.arrive')).toBe(true);
  });

  it('усі відображувані статуси мають переклад (8.7)', () => {
    for (const status of [
      'booked',
      'arrived',
      'unloading',
      'completed',
      'cancelled',
      'no_show',
    ]) {
      expect(i18n.has(`status.${status}`)).toBe(true);
    }
  });
});
