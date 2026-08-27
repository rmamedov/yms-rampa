import { inject, Pipe, PipeTransform } from '@angular/core';
import { I18nService, TranslateParams } from './i18n.service';

@Pipe({ name: 't' })
export class TranslatePipe implements PipeTransform {
  private readonly i18n = inject(I18nService);

  transform(key: string, params?: TranslateParams): string {
    return this.i18n.t(key, params);
  }
}
