import { Injectable, inject, signal } from '@angular/core';
import { I18nService } from '../../core/i18n/i18n.service';
import { toProblem, type ApiProblem } from '../../core/api/problem';

export type ToastKind = 'success' | 'error' | 'info';

export interface Toast {
  readonly id: number;
  readonly kind: ToastKind;
  readonly text: string;
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly i18n = inject(I18nService);
  private sequence = 1;
  readonly toasts = signal<readonly Toast[]>([]);

  success(text: string): void {
    this.push('success', text);
  }

  info(text: string): void {
    this.push('info', text);
  }

  error(text: string): void {
    this.push('error', text);
  }

  /** Показує повідомлення з problem-документа бекенду. */
  problem(error: unknown): ApiProblem {
    const problem = toProblem(error);
    this.error(this.messageFor(problem));
    return problem;
  }

  /** Відоме повідомлення за кодом помилки, інакше — detail з бекенду. */
  messageFor(problem: ApiProblem): string {
    const key = `error.${problem.code}`;
    if (this.i18n.has(key)) {
      return this.i18n.t(key, (problem.meta ?? {}) as Record<string, string>);
    }
    return problem.detail || this.i18n.t('error.UNKNOWN');
  }

  dismiss(id: number): void {
    this.toasts.update((list) => list.filter((toast) => toast.id !== id));
  }

  private push(kind: ToastKind, text: string): void {
    const toast: Toast = { id: this.sequence++, kind, text };
    this.toasts.update((list) => [...list, toast]);
    setTimeout(() => this.dismiss(toast.id), 6000);
  }
}
