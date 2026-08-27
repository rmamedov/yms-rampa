import { I18nService } from './i18n.service';
import { UK_DICTIONARY } from './uk.dictionary';
import { ALL_STATUSES } from '../util/status.util';
import {
  DELAY_REASONS,
  PARTIAL_UNLOAD_REASONS,
  REJECT_REASONS,
} from '../models/booking.model';

describe('I18nService', () => {
  let i18n: I18nService;

  beforeEach(() => {
    i18n = new I18nService();
  });

  it('повертає український рядок за ключем', () => {
    expect(i18n.translate('status.arrived')).toBe('Очікує на території');
  });

  it('підставляє параметри у шаблон', () => {
    expect(i18n.translate('stats.avgWaitValue', { minutes: 17 })).toBe('17 хв');
    expect(
      i18n.translate('card.unloadedPallets', { done: 20, planned: 26 }),
    ).toBe('Розвантажено 20 з 26 палет');
  });

  it('лишає невідомі плейсхолдери недоторканими', () => {
    i18n.merge({ 'test.key': 'A {known} B {unknown}' });
    expect(i18n.translate('test.key', { known: 'X' })).toBe('A X B {unknown}');
  });

  it('повертає сам ключ, якщо перекладу немає', () => {
    expect(i18n.translate('нема.такого.ключа')).toBe('нема.такого.ключа');
    expect(i18n.has('нема.такого.ключа')).toBe(false);
  });

  it('має переклади для всіх статусів', () => {
    for (const status of ALL_STATUSES) {
      expect(i18n.has(`status.${status}`)).toBe(true);
    }
  });

  /**
   * Довідники причин бекенду — це backed-enum'и з україномовними значеннями
   * (`RejectionReason`, `PartialUnloadReason`, `DelayReason`), тому UI показує
   * значення як є і окремих ключів i18n для них не існує. Тест закріплює саме
   * цю властивість: жодних латинських кодів причин у контракті бути не має.
   */
  it('значення довідників причин уже україномовні і не потребують ключів', () => {
    const cyrillic = /^[а-яїієґА-ЯЇІЄҐ][а-яїієґА-ЯЇІЄҐ\s/'’-]*$/;
    for (const reason of [
      ...REJECT_REASONS,
      ...PARTIAL_UNLOAD_REASONS,
      ...DELAY_REASONS,
    ]) {
      expect(reason).toMatch(cyrillic);
      expect(i18n.has(`rejectReason.${reason}`)).toBe(false);
    }
  });

  it('словник не містить порожніх значень', () => {
    const empty = Object.entries(UK_DICTIONARY).filter(
      ([, value]) => value.trim().length === 0,
    );
    expect(empty).toEqual([]);
  });
});
