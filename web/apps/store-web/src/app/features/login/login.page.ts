import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { describeError } from '../../core/api/problem.util';
import { TranslatePipe } from '../../core/i18n/translate.pipe';

/** Екран входу staff-контуру (STW-01). */
@Component({
  selector: 'app-login-page',
  standalone: true,
  imports: [FormsModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <form class="panel login__card" (submit)="submit($event)">
        <h1 class="login__title">{{ 'login.heading' | t }}</h1>
        <p class="muted">{{ 'login.subheading' | t }}</p>

        <div class="field">
          <label class="field__label" for="email">{{ 'login.email' | t }}</label>
          <input
            id="email"
            class="input"
            type="email"
            autocomplete="username"
            [ngModel]="email()"
            (ngModelChange)="email.set($event)"
            name="email"
          />
        </div>

        <div class="field">
          <label class="field__label" for="password">{{
            'login.password' | t
          }}</label>
          <input
            id="password"
            class="input"
            type="password"
            autocomplete="current-password"
            [ngModel]="password()"
            (ngModelChange)="password.set($event)"
            name="password"
          />
        </div>

        @if (errorKey() || errorText()) {
          <p class="form-error">
            {{ errorKey() ? (errorKey()! | t) : errorText() }}
          </p>
        }

        <button
          type="submit"
          class="btn btn--primary btn--block"
          [disabled]="busy()"
        >
          {{ (busy() ? 'login.inProgress' : 'login.submit') | t }}
        </button>

        <p class="login__hint muted">{{ 'login.hint' | t }}</p>
      </form>
    </main>
  `,
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly email = signal('operator@silpo.ua');
  readonly password = signal('demo');
  readonly busy = signal(false);
  readonly errorKey = signal<string | null>(null);
  readonly errorText = signal<string | null>(null);

  readonly canSubmit = computed(
    () => this.email().trim().length > 0 && this.password().length > 0,
  );

  submit(event: Event): void {
    event.preventDefault();
    this.errorKey.set(null);
    this.errorText.set(null);

    if (!this.canSubmit()) {
      this.errorKey.set('login.failed');
      return;
    }

    this.busy.set(true);
    this.auth
      .login({ email: this.email().trim(), password: this.password() })
      .subscribe({
        next: (profile) => {
          this.busy.set(false);
          const target = this.auth.hasStoreAccess() ? '/today' : '/no-access';
          void this.router.navigateByUrl(target);
          void profile;
        },
        error: (error: unknown) => {
          this.busy.set(false);
          const described = describeError(error);
          this.errorKey.set(described.key);
          this.errorText.set(described.text);
        },
      });
  }
}
