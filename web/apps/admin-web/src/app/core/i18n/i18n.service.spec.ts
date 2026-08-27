import { TestBed } from '@angular/core/testing';
import { I18nService } from './i18n.service';
import { UK_DICTIONARY } from './uk';
import { KNOWN_ERROR_CODES } from '../http/problem';

describe('I18nService', () => {
  let i18n: I18nService;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [I18nService] });
    i18n = TestBed.inject(I18nService);
  });

  it('віддає українські рядки за ключем', () => {
    expect(i18n.t('stores.title')).toBe('Магазини');
    expect(i18n.t('ymsStatus.not_configured')).toBe('Не налаштовано');
  });

  it('підставляє параметри в шаблон', () => {
    expect(i18n.t('suppliers.access.whitelist', { n: 3 })).toBe(
      'Перелік магазинів (3)',
    );
    expect(i18n.t('common.selected', { count: 12 })).toBe('Вибрано: 12');
  });

  it('повертає сам ключ, якщо перекладу немає', () => {
    expect(i18n.t('no.such.key')).toBe('no.such.key');
    expect(i18n.has('no.such.key')).toBe(false);
  });

  it('лишає плейсхолдер, якщо параметр не передано', () => {
    expect(i18n.t('suppliers.access.whitelist')).toBe('Перелік магазинів ({n})');
  });

  it('має переклади для ВСІХ відомих кодів помилок бекенду', () => {
    for (const code of KNOWN_ERROR_CODES) {
      expect(i18n.has(`error.${code}`)).toBe(true);
    }
  });

  it('словник не містить порожніх значень', () => {
    const empty = Object.entries(UK_DICTIONARY).filter(([, value]) => value.trim() === '');
    expect(empty).toEqual([]);
  });
});
