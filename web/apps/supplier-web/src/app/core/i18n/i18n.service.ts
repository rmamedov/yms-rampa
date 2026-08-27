import { Injectable, Pipe, PipeTransform, inject } from '@angular/core';
import { UK_DICTIONARY } from './uk';

export type TranslateParams = Record<string, string | number>;

/**
 * Мінімалістичний сервіс перекладів: єдиний український словник,
 * підстановка {параметрів} та українська плюралізація.
 */
@Injectable({ providedIn: 'root' })
export class I18nService {
  readonly locale = 'uk-UA';
  private readonly dictionary: Record<string, string> = UK_DICTIONARY;

  t(key: string, params?: TranslateParams): string {
    const template = this.dictionary[key];
    if (template === undefined) {
      return key;
    }
    return interpolate(template, params);
  }

  /** true, якщо ключ є у словнику (використовується для кодів помилок). */
  has(key: string): boolean {
    return Object.prototype.hasOwnProperty.call(this.dictionary, key);
  }

  /** Українська плюралізація: 1 магазин / 2 магазини / 5 магазинів. */
  plural(count: number, one: string, few: string, many: string): string {
    const abs = Math.abs(Math.trunc(count));
    const mod10 = abs % 10;
    const mod100 = abs % 100;
    if (mod10 === 1 && mod100 !== 11) {
      return one;
    }
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
      return few;
    }
    return many;
  }

  /** «1 точка / 2 точки / 5 точок» для маршрутних листів. */
  pointsCount(count: number): string {
    const word = this.plural(
      count,
      this.t('rs.pointWord.one'),
      this.t('rs.pointWord.few'),
      this.t('rs.pointWord.many'),
    );
    return this.t('rs.points', { count, word });
  }

  storeCount(count: number): string {
    const word = this.plural(
      count,
      this.t('city.storeWord.one'),
      this.t('city.storeWord.few'),
      this.t('city.storeWord.many'),
    );
    return this.t('city.storeCount', { count, word });
  }
}

export function interpolate(
  template: string,
  params?: TranslateParams,
): string {
  if (!params) {
    return template;
  }
  return template.replace(/\{(\w+)\}/g, (match, name: string) => {
    const value = params[name];
    return value === undefined ? match : String(value);
  });
}

@Pipe({ name: 't' })
export class TranslatePipe implements PipeTransform {
  private readonly i18n = inject(I18nService);

  transform(key: string, params?: TranslateParams): string {
    return this.i18n.t(key, params);
  }
}
