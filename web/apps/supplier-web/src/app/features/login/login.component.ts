import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslatePipe } from '../../core/i18n/i18n.service';
import { AuthService } from '../../core/auth/auth.service';
import { ERROR_CODES, toProblem } from '../../core/api/problem';
import { validateEmail } from '../../core/util/validation';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-login',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, TranslatePipe],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly email = signal('');
  protected readonly password = signal('');
  protected readonly pending = signal(false);
  protected readonly errorKey = signal<string | null>(null);
  protected readonly driverHint = signal(false);
  protected readonly showDemo = environment.useMocks;
  protected readonly demo = environment.demoLogin;

  protected submit(): void {
    if (this.pending()) {
      return;
    }
    this.errorKey.set(null);
    this.driverHint.set(false);

    const email = this.email().trim();
    const password = this.password();
    if (looksLikePhone(email)) {
      // DRV-10: бекенд не розкриває причину відмови (AUTH_INVALID_CREDENTIALS),
      // тому підказку про застосунок водія даємо на клієнті, до запиту.
      this.driverHint.set(true);
      this.errorKey.set('login.driverAccount');
      return;
    }
    if (validateEmail(email) || !password) {
      // SUP-AUTH-02: єдине неспецифічне повідомлення.
      this.errorKey.set('login.invalid');
      return;
    }

    this.pending.set(true);
    this.auth.login(email, password).subscribe({
      next: () => {
        this.pending.set(false);
        const target = this.auth.returnUrl() ?? '/home';
        this.auth.returnUrl.set(null);
        void this.router.navigateByUrl(target);
      },
      error: (error: unknown) => {
        this.pending.set(false);
        const problem = toProblem(error);
        if (problem.code === ERROR_CODES.authAccountLocked) {
          this.errorKey.set('login.locked');
          return;
        }
        if (problem.code === ERROR_CODES.authAccountDisabled) {
          this.errorKey.set('login.disabled');
          return;
        }
        this.errorKey.set('login.invalid');
      },
    });
  }
}

/** Логін водія — телефон; кабінет постачальника приймає лише e-mail. */
function looksLikePhone(login: string): boolean {
  return /^\+?3?8?0\d{9}$/.test(login.replace(/[\s()-]/g, ''));
}
