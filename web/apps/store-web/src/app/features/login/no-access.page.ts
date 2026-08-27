import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';

/** «Доступ заборонено» без витоку даних (STW-01). */
@Component({
  selector: 'app-no-access-page',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <section class="panel login__card">
        <h1 class="login__title">{{ 'access.denied.title' | t }}</h1>
        <p>{{ 'access.denied.text' | t }}</p>
        <button type="button" class="btn btn--block" (click)="logout()">
          {{ 'access.denied.logout' | t }}
        </button>
      </section>
    </main>
  `,
})
export class NoAccessPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  logout(): void {
    this.auth.logout();
    void this.router.navigateByUrl('/login');
  }
}
