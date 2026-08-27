import { Injectable, signal } from '@angular/core';
import { UK_DICTIONARY } from './uk.dictionary';

export type TranslateParams = Record<string, string | number>;

/**
 * Простий сервіс перекладів: один український словник, підстановка {параметрів}.
 * Жодного хардкоду текстів у шаблонах — усе через ключі.
 */
@Injectable({ providedIn: 'root' })
export class I18nService {
  private readonly dictionary = signal<Record<string, string>>(UK_DICTIONARY);
  readonly locale = 'uk-UA';

  /** Повертає переклад; якщо ключа немає — сам ключ (щоб одразу було видно). */
  translate(key: string, params?: TranslateParams): string {
    const template = this.dictionary()[key];
    if (template === undefined) {
      return key;
    }
    if (!params) {
      return template;
    }
    return template.replace(/\{(\w+)\}/g, (match, name: string) => {
      const value = params[name];
      return value === undefined ? match : String(value);
    });
  }

  has(key: string): boolean {
    return this.dictionary()[key] !== undefined;
  }

  /** Для тестів / майбутнього розширення на інші мови. */
  merge(extra: Record<string, string>): void {
    this.dictionary.update((current) => ({ ...current, ...extra }));
  }
}
