import { TestBed } from '@angular/core/testing';
import { I18nService } from './i18n.service';

describe('I18nService', () => {
  let i18n: I18nService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    i18n = TestBed.inject(I18nService);
  });

  it('повертає українські рядки за ключем', () => {
    expect(i18n.t('point.route')).toBe('Побудувати маршрут');
    expect(i18n.t('status.booked')).toBe('Очікує виїзду');
  });

  it('підставляє параметри у шаблон', () => {
    expect(i18n.t('sheet.updatedAt', { time: '10:15' })).toBe('Оновлено 10:15');
    expect(i18n.t('offline.banner', { time: '10:15' })).toBe(
      'Немає звʼязку. Показано збережений маршрут на 10:15',
    );
  });

  it('невідомий ключ повертається як є і не ламає екран', () => {
    expect(i18n.t('no.such.key')).toBe('no.such.key');
    expect(i18n.has('no.such.key')).toBe(false);
    expect(i18n.has('point.route')).toBe(true);
  });

  it('усі відображувані статуси мають переклад (8.7)', () => {
    for (const status of [
      'booked',
      'arrived',
      'unloading',
      'completed',
      'cancelled',
      'no_show',
      'rejected',
    ]) {
      expect(i18n.has(`status.${status}`)).toBe(true);
    }
  });
});
