import { Injectable, signal } from '@angular/core';
import { UK_DICTIONARY } from './uk';

export type TranslateParams = Readonly<Record<string, string | number>>;

/**
 * Простий сервіс перекладів. Мова інтерфейсу — українська;
 * усі рядки шаблонів беруться звідси за ключем (жодного хардкоду).
 */
@Injectable({ providedIn: 'root' })
export class I18nService {
  private readonly dictionary = signal<Readonly<Record<string, string>>>(
    UK_DICTIONARY,
  );

  readonly locale = 'uk-UA';
  readonly timeZone = 'Europe/Kyiv';

  /** Повертає переклад; за відсутності ключа — сам ключ (щоб дефект було видно). */
  t(key: string, params?: TranslateParams): string {
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

  /** Дозволяє точково доповнити словник (напр. у тестах). */
  extend(entries: Readonly<Record<string, string>>): void {
    this.dictionary.update((current) => ({ ...current, ...entries }));
  }
}
