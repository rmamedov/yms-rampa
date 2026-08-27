import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { I18nService } from '../../core/i18n/i18n.service';
import { ApiError, parseProblem } from '../../core/http/problem';
import { environment } from '../../../environments/environment';

interface DemoAccount {
  readonly email: string;
  readonly roleKey: string;
}

const DEMO_ACCOUNTS: readonly DemoAccount[] = [
  { email: 'super.admin@silpo.ua', roleKey: 'role.super_admin' },
  { email: 'network.manager@silpo.ua', roleKey: 'role.network_manager' },
  { email: 'store.manager@silpo.ua', roleKey: 'role.store_manager' },
  { email: 'analyst@silpo.ua', roleKey: 'role.analyst' },
  { email: 'store.operator@silpo.ua', roleKey: 'role.store_operator' },
];

/** Екран входу — контур staff (ADM-01). */
@Component({
  selector: 'app-login-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <div class="login-page">
      <form class="login-card" (submit)="submit($event)">
        <div class="login-brand">{{ 'app.brand' | t }}</div>
        <h2>{{ 'login.title' | t }}</h2>
        <p class="muted">{{ 'login.subtitle' | t }}</p>

        <div class="field">
          <label for="email">{{ 'login.email' | t }}</label>
          <input
            id="email"
            type="email"
            autocomplete="username"
            [value]="email()"
            (input)="email.set($any($event.target).value)"
          />
        </div>

        <div class="field">
          <label for="password">{{ 'login.password' | t }}</label>
          <input
            id="password"
            type="password"
            autocomplete="current-password"
            [value]="password()"
            (input)="password.set($any($event.target).value)"
          />
        </div>

        @if (error()) {
          <div class="notice notice-danger">{{ error() }}</div>
        }

        <button
          type="submit"
          class="btn btn-primary"
          style="width: 100%; justify-content: center"
          [disabled]="busy()"
        >
          {{ busy() ? ('common.loading' | t) : ('login.submit' | t) }}
        </button>

        @if (useMocks) {
          <div class="login-demo">
            <span class="muted">{{ 'login.demoHint' | t }}</span>
            <div class="login-demo-list">
              @for (account of demoAccounts; track account.email) {
                <button
                  type="button"
                  class="btn btn-sm"
                  (click)="pick(account.email)"
                >
                  {{ account.roleKey | t }} — {{ account.email }}
                </button>
              }
            </div>
          </div>
        }
      </form>
    </div>
  `,
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);

  protected readonly email = signal('super.admin@silpo.ua');
  protected readonly password = signal('demo');
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly demoAccounts = DEMO_ACCOUNTS;
  protected readonly useMocks = environment.useMocks;

  protected pick(email: string): void {
    this.email.set(email);
    this.password.set('demo');
  }

  protected submit(event: Event): void {
    event.preventDefault();
    if (this.busy()) {
      return;
    }
    const email = this.email().trim();
    const password = this.password();
    if (email === '' || password === '') {
      this.error.set(this.i18n.t('login.error.required'));
      return;
    }
    this.error.set(null);
    this.busy.set(true);
    this.auth.login(email, password).subscribe({
      next: () => {
        this.busy.set(false);
        void this.router.navigate(['/']);
      },
      error: (raw: unknown) => {
        this.busy.set(false);
        this.error.set(this.describe(parseProblem(raw)));
      },
    });
  }

  private describe(error: ApiError): string {
    if (error.status === 403) {
      return error.problem.detail ?? this.i18n.t('login.error.forbidden');
    }
    if (error.status === 401) {
      return this.i18n.t('login.error.invalid');
    }
    return error.problem.detail ?? this.i18n.t('error.unknown');
  }
}
