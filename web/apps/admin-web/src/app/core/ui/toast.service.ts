import { inject, Injectable, signal } from '@angular/core';
import { I18nService, TranslateParams } from '../i18n/i18n.service';
import { ApiError, parseProblem, problemMessageKeyOrText } from '../http/problem';

export type ToastKind = 'success' | 'error' | 'info';

export interface Toast {
  readonly id: number;
  readonly kind: ToastKind;
  readonly text: string;
}

/** UI-04: серверні помилки — toast-повідомленням з текстом причини українською. */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly i18n = inject(I18nService);
  private nextId = 1;

  readonly toasts = signal<readonly Toast[]>([]);

  success(key: string, params?: TranslateParams): void {
    this.push('success', this.i18n.t(key, params));
  }

  info(key: string, params?: TranslateParams): void {
    this.push('info', this.i18n.t(key, params));
  }

  errorKey(key: string, params?: TranslateParams): void {
    this.push('error', this.i18n.t(key, params));
  }

  /** Розбирає problem+json і показує повідомлення з detail або зі словника за code. */
  error(error: unknown): ApiError {
    const apiError = parseProblem(error);
    const message = problemMessageKeyOrText(apiError);
    this.push('error', message.key ? this.i18n.t(message.key) : (message.text ?? ''));
    return apiError;
  }

  dismiss(id: number): void {
    this.toasts.update((list) => list.filter((t) => t.id !== id));
  }

  clear(): void {
    this.toasts.set([]);
  }

  private push(kind: ToastKind, text: string): void {
    const toast: Toast = { id: this.nextId++, kind, text };
    this.toasts.update((list) => [...list, toast]);
    if (typeof setTimeout === 'function') {
      setTimeout(() => this.dismiss(toast.id), 6000);
    }
  }
}
