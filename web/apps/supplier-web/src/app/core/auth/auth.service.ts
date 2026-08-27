import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, finalize, shareReplay, tap, throwError } from 'rxjs';
import { AuthApi } from '../api/contracts';
import { ERROR_CODES, problemError } from '../api/problem';
import type { AuthSession, AuthTokens, SupplierUser } from '../models/models';
import { TokenStorage } from './token-storage.service';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(AuthApi);
  private readonly storage = inject(TokenStorage);
  private readonly router = inject(Router);

  private readonly session = signal<AuthSession | null>(this.storage.read());
  private refreshInFlight: Observable<AuthTokens> | null = null;

  readonly user = computed<SupplierUser | null>(
    () => this.session()?.user ?? null,
  );
  readonly isAuthenticated = computed(() => this.session() !== null);
  /** URL, на який слід повернути користувача після повторного входу. */
  readonly returnUrl = signal<string | null>(null);

  accessToken(): string | null {
    return this.session()?.accessToken ?? null;
  }

  login(login: string, password: string): Observable<AuthSession> {
    return this.api.login(login, password).pipe(
      tap((session) => {
        this.session.set(session);
        this.storage.write(session);
      }),
    );
  }

  /** Спільний refresh: паралельні 401 очікують один запит. */
  refreshTokens(): Observable<AuthTokens> {
    if (this.refreshInFlight) {
      return this.refreshInFlight;
    }
    const current = this.session();
    if (!current) {
      return throwError(() =>
        problemError(401, ERROR_CODES.unauthorized, 'Сесія завершилась'),
      );
    }
    this.refreshInFlight = this.api.refresh(current.refreshToken).pipe(
      tap((tokens) => {
        const updated: AuthSession = { ...tokens, user: current.user };
        this.session.set(updated);
        this.storage.updateTokens(tokens);
      }),
      finalize(() => {
        this.refreshInFlight = null;
      }),
      shareReplay({ bufferSize: 1, refCount: true }),
    );
    return this.refreshInFlight;
  }

  logout(): void {
    this.api.logout().subscribe({ error: () => undefined });
    this.clearSession();
    void this.router.navigate(['/login']);
  }

  /** Примусовий вихід після невдалого refresh — зі збереженням URL повернення. */
  expireSession(returnUrl?: string): void {
    this.clearSession();
    if (returnUrl && !returnUrl.startsWith('/login')) {
      this.returnUrl.set(returnUrl);
    }
    void this.router.navigate(['/login']);
  }

  private clearSession(): void {
    this.session.set(null);
    this.storage.clear();
    this.refreshInFlight = null;
  }
}
