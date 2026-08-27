import { Injectable, Pipe, PipeTransform, inject } from '@angular/core';
import { UK_DICTIONARY, TranslationKey } from './uk';

/**
 * Мінімалістичний сервіс перекладів: єдиний словник (українська),
 * підстановка параметрів у стилі {name}.
 */
@Injectable({ providedIn: 'root' })
export class I18nService {
  readonly locale = 'uk-UA';

  private readonly dictionary: Record<string, string> = UK_DICTIONARY;

  t(key: TranslationKey | string, params?: Record<string, string | number>): string {
    const template = this.dictionary[key];
    if (template === undefined) {
      // Відсутній ключ не повинен ламати екран — показуємо сам ключ.
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
    return key in this.dictionary;
  }
}

@Pipe({ name: 't', pure: true })
export class TranslatePipe implements PipeTransform {
  private readonly i18n = inject(I18nService);

  transform(
    key: TranslationKey | string,
    params?: Record<string, string | number>,
  ): string {
    return this.i18n.t(key, params);
  }
}
