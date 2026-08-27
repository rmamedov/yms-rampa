import { computed, inject, Injectable, signal } from '@angular/core';
import { catchError, map, Observable, of, shareReplay, tap, throwError } from 'rxjs';
import { AuthSession, AuthTokens, Permission, StaffRole } from '../models';
import { ADMIN_WEB_ROLES, grantFor, SectionId, SECTION_PERMISSION } from '../rbac/permissions';
import { ApiError } from '../http/problem';
import { TokenStorageService } from './token-storage.service';
import { AuthApi } from '../data/auth.api';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(AuthApi);
  private readonly storage = inject(TokenStorageService);

  private readonly sessionState = signal<AuthSession | null>(this.storage.read());
  private refreshInFlight: Observable<AuthTokens> | null = null;

  readonly session = this.sessionState.asReadonly();
  readonly user = computed(() => this.sessionState()?.user ?? null);
  readonly role = computed<StaffRole | null>(() => this.user()?.role ?? null);
  readonly isAuthenticated = computed(() => this.sessionState() !== null);
  readonly storeIds = computed<readonly string[]>(() => this.user()?.storeIds ?? []);

  accessToken(): string | null {
    return this.sessionState()?.tokens.accessToken ?? null;
  }

  refreshToken(): string | null {
    return this.sessionState()?.tokens.refreshToken ?? null;
  }

  /** ADM-01: до admin-web допускаються лише staff-ролі super_admin/network_manager/store_manager/analyst. */
  login(email: string, password: string): Observable<AuthSession> {
    return this.api.login(email, password).pipe(
      map((session) => {
        if (!(ADMIN_WEB_ROLES as readonly string[]).includes(session.user.role)) {
          throw new ApiError(403, {
            code: 'RBAC_PERMISSION_DENIED',
            detail: 'Недостатньо прав для доступу до адмін-панелі',
          });
        }
        return session;
      }),
      tap((session) => this.setSession(session)),
    );
  }

  /**
   * AUTH-32: сесію гасить бекенд (POST /auth/logout). Локальний стан
   * очищується незалежно від результату — токен усе одно більше не потрібен.
   */
  logout(): void {
    const token = this.refreshToken();
    if (token) {
      this.api
        .logout(token)
        .pipe(catchError(() => of(undefined)))
        .subscribe();
    }
    this.clearSession();
  }

  private clearSession(): void {
    this.sessionState.set(null);
    this.refreshInFlight = null;
    this.storage.clear();
  }

  setSession(session: AuthSession): void {
    this.sessionState.set(session);
    this.storage.write(session);
  }

  /** Оновлення токена при 401; паралельні запити чекають один і той самий refresh. */
  refresh(): Observable<AuthTokens> {
    if (this.refreshInFlight) {
      return this.refreshInFlight;
    }
    const token = this.refreshToken();
    if (!token) {
      return throwError(
        () => new ApiError(401, { code: 'AUTH_TOKEN_INVALID' }),
      );
    }
    this.refreshInFlight = this.api.refresh(token).pipe(
      tap((tokens) => {
        const current = this.sessionState();
        if (current) {
          this.setSession({ ...current, tokens });
        }
        this.refreshInFlight = null;
      }),
      catchError((error: unknown) => {
        this.clearSession();
        return throwError(() => error);
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    return this.refreshInFlight;
  }

  /** RBAC-02: deny by default. */
  can(permission: Permission): boolean {
    const role = this.role();
    if (!role) {
      return false;
    }
    return grantFor(role, permission) !== 'denied';
  }

  grant(permission: Permission) {
    const role = this.role();
    return role ? grantFor(role, permission) : 'denied';
  }

  /** ADM-02: видимість розділів визначається роллю. */
  canSee(section: SectionId): boolean {
    return this.can(SECTION_PERMISSION[section]);
  }

  /** ADM-05: конфігураційні вкладки редагують лише super_admin і network_manager. */
  canConfigureStores(): boolean {
    return this.grant('store.configure') === 'full';
  }

  /** store_manager може блокувати слоти лише у своїх магазинах (slot.block = S). */
  canBlockSlots(storeId: string | null): boolean {
    const g = this.grant('slot.block');
    if (g === 'full') {
      return true;
    }
    if (g === 'scoped') {
      return storeId !== null && this.storeIds().includes(storeId);
    }
    return false;
  }

  /** RBAC-13: порожній storeIds = нуль доступу. */
  canReadStore(storeId: string): boolean {
    const g = this.grant('store.read');
    if (g === 'full') {
      return true;
    }
    if (g === 'scoped') {
      return this.storeIds().includes(storeId);
    }
    return false;
  }

  /** Мок-логін без бекенду має віддавати ті самі структури. */
  restoreFromStorage(): Observable<AuthSession | null> {
    return of(this.sessionState());
  }
}
