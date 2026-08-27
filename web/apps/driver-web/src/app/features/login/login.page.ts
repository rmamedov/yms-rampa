import {
  ChangeDetectionStrategy,
  Component,
  inject,
  signal,
} from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { I18nService, TranslatePipe } from '../../core/i18n/i18n.service';
import { ProblemMessageService } from '../../core/http/problem-message.service';
import { isValidPhone, normalizePhone } from '../../core/util/phone.util';
import { firstValueFrom } from 'rxjs';

@Component({
  selector: 'app-login-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './login.page.html',
  styleUrl: './login.page.scss',
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly problems = inject(ProblemMessageService);
  protected readonly i18n = inject(I18nService);

  protected readonly phone = signal('');
  protected readonly password = signal('');
  protected readonly rememberMe = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly phoneError = signal<string | null>(null);
  protected readonly passwordError = signal<string | null>(null);

  protected onPhoneInput(event: Event): void {
    this.phone.set((event.target as HTMLInputElement).value);
    this.phoneError.set(null);
    this.error.set(null);
  }

  protected onPasswordInput(event: Event): void {
    this.password.set((event.target as HTMLInputElement).value);
    this.passwordError.set(null);
    this.error.set(null);
  }

  protected onRememberChange(event: Event): void {
    this.rememberMe.set((event.target as HTMLInputElement).checked);
  }

  /** Нормалізація телефону просто у полі при втраті фокусу. */
  protected onPhoneBlur(): void {
    const normalized = normalizePhone(this.phone());
    if (normalized) {
      this.phone.set(normalized);
    }
  }

  protected async submit(event: Event): Promise<void> {
    event.preventDefault();
    if (this.submitting()) {
      return;
    }
    const phone = this.phone().trim();
    const password = this.password();

    let valid = true;
    if (!phone) {
      this.phoneError.set(this.i18n.t('login.error.phoneRequired'));
      valid = false;
    } else if (!isValidPhone(phone)) {
      this.phoneError.set(this.i18n.t('login.error.phoneInvalid'));
      valid = false;
    }
    if (!password) {
      this.passwordError.set(this.i18n.t('login.error.passwordRequired'));
      valid = false;
    }
    if (!valid) {
      return;
    }

    this.submitting.set(true);
    this.error.set(null);
    try {
      await firstValueFrom(this.auth.login(phone, password, this.rememberMe()));
      await this.router.navigate(['/route']);
    } catch (err) {
      this.error.set(this.problems.messageFor(err, 'login.error.generic'));
    } finally {
      this.submitting.set(false);
    }
  }
}
