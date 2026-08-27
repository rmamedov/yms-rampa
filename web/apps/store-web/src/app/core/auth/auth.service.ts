import { computed, inject, Injectable, signal } from '@angular/core';
import { Observable, map, of, shareReplay, tap, throwError } from 'rxjs';
import {
  AuthTokens,
  LoginRequest,
  NETWORK_WIDE_ROLES,
  StaffProfile,
  STORE_ROLES,
  StoreScope,
} from '../models/auth.model';
import { AppError } from '../models/problem.model';
import { StoreConfig } from '../models/store.model';
import { AuthGateway, StoreGateway } from '../data/gateways';
import { TokenStorageService } from './token-storage.service';

/**
 * Сесія staff-контуру: логін, refresh, RBAC-перевірка ролі (STW-01)
 * та вибір поточного магазину (STW-03).
 *
 * ПЕРЕЛІК МАГАЗИНІВ береться з GET /api/store/v1/stores, а не з
 * `profile.storeIds`. Причина: у мережевих ролей (super_admin,
 * network_manager) скоуп задає РОЛЬ, а не перелік філій (RBAC-16), тому
 * `storeIds` у них порожній — перемикач, побудований із профілю, лишався
 * порожнім назавжди, магазин не обирався і жоден екран не вантажився.
 * Маршрут же вже враховує права: магазинні ролі отримують свої філії,
 * мережеві — усі активні.
 *
 * `profile.storeIds` лишається запасним варіантом на час, доки маршрут ще не
 * відповів (перший кадр після перезавантаження), і для магазинних ролей дає
 * той самий склад переліку.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly gateway = inject(AuthGateway);
  private readonly storeGateway = inject(StoreGateway);
  private readonly storage = inject(TokenStorageService);

  private readonly profileSignal = signal<StaffProfile | null>(
    this.storage.getProfile(),
  );
  private readonly selectedStoreIdSignal = signal<string | null>(
    this.storage.getSelectedStoreId(),
  );
  private readonly labelsSignal = signal<Readonly<Record<string, StoreScope>>>({});
  /** Перелік із GET /stores; null — ще не завантажено. */
  private readonly scopeSignal = signal<readonly StoreScope[] | null>(
    this.storage.getStores(),
  );

  /** Запит, що вже в польоті: паралельні екрани не мають дублювати його. */
  private inFlight: Observable<readonly StoreScope[]> | null = null;

  readonly profile = this.profileSignal.asReadonly();

  readonly isAuthenticated = computed(() => this.profileSignal() !== null);

  /** STW-01: доступ мають магазинні і мережеві ролі (канонічна матриця прав). */
  readonly hasStoreAccess = computed(() => {
    const profile = this.profileSignal();
    return profile !== null && STORE_ROLES.includes(profile.role);
  });

  /** RBAC-16: скоуп таких ролей задає роль, а не перелік `storeIds`. */
  readonly isNetworkWide = computed(() => {
    const profile = this.profileSignal();
    if (profile === null) return false;
    return profile.networkWide || NETWORK_WIDE_ROLES.includes(profile.role);
  });

  readonly stores = computed<readonly StoreScope[]>(() => {
    const labels = this.labelsSignal();
    const fromServer = this.scopeSignal();
    const list =
      fromServer ??
      (this.profileSignal()?.storeIds ?? []).map((storeId) => ({
        storeId,
        displayName: storeId,
        externalId: null,
        city: null,
        address: null,
      }));
    // Конфігурація магазину уточнює підпис уже завантаженої філії.
    return list.map((store) => labels[store.storeId] ?? store);
  });

  /** Перемикач магазину показуємо лише за наявності 2+ магазинів (STW-03). */
  readonly showStoreSwitcher = computed(() => this.stores().length > 1);

  readonly selectedStore = computed<StoreScope | null>(() => {
    const list = this.stores();
    if (!list.length) return null;
    const id = this.selectedStoreIdSignal();
    return list.find((s) => s.storeId === id) ?? list[0];
  });

  readonly isManager = computed(
    () => this.profileSignal()?.role === 'store_manager',
  );

  login(request: LoginRequest): Observable<StaffProfile> {
    return this.gateway.login(request).pipe(
      map((response) => {
        this.storage.setTokens(response.tokens);
        this.storage.setProfile(response.profile);
        this.profileSignal.set(response.profile);
        // Перелік філій належить попередній сесії — його треба перечитати.
        this.resetStores();
        this.restoreStoreSelection(response.profile.storeIds);
        return response.profile;
      }),
    );
  }

  /**
   * Гарантує завантажений перелік філій. Повторні виклики (перемикання
   * магазину, паралельні екрани) не породжують нових запитів.
   */
  ensureStores(): Observable<readonly StoreScope[]> {
    const loaded = this.scopeSignal();
    if (loaded !== null) return of(loaded);
    return this.loadStores();
  }

  /** Перечитує перелік філій із бекенду. */
  loadStores(): Observable<readonly StoreScope[]> {
    if (this.inFlight) return this.inFlight;
    this.inFlight = this.storeGateway.getStores().pipe(
      tap({
        next: (list) => {
          this.inFlight = null;
          this.applyStores(list);
        },
        error: () => {
          this.inFlight = null;
        },
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    return this.inFlight;
  }

  /** AUTH-31: refresh повертає нову пару токенів разом із профілем. */
  refresh(): Observable<AuthTokens> {
    const refreshToken = this.storage.getTokens()?.refreshToken ?? null;
    if (!refreshToken) {
      return throwError(
        () =>
          new AppError(
            { status: 401, code: 'AUTH_TOKEN_INVALID' },
            'error.AUTH_TOKEN_INVALID',
          ),
      );
    }
    return this.gateway.refresh(refreshToken).pipe(
      tap((response) => {
        this.storage.setTokens(response.tokens);
        this.storage.setProfile(response.profile);
        this.profileSignal.set(response.profile);
      }),
      map((response) => response.tokens),
    );
  }

  logout(): void {
    const refreshToken = this.storage.getTokens()?.refreshToken ?? null;
    this.gateway.logout(refreshToken).subscribe({ error: () => undefined });
    this.storage.clearSession();
    this.profileSignal.set(null);
    this.selectedStoreIdSignal.set(null);
    this.labelsSignal.set({});
    this.resetStores();
  }

  /** STW-03: вибір магазину зберігається між сесіями. */
  selectStore(storeId: string): void {
    if (!this.stores().some((s) => s.storeId === storeId)) return;
    this.selectedStoreIdSignal.set(storeId);
    this.storage.setSelectedStoreId(storeId);
  }

  /** Уточнює підпис магазину після завантаження його конфігурації. */
  describeStore(config: StoreConfig): void {
    this.labelsSignal.update((labels) => ({
      ...labels,
      [config.storeId]: {
        storeId: config.storeId,
        displayName: config.displayName,
        externalId: config.externalId,
        city: config.city,
        address: config.address,
      },
    }));
  }

  /**
   * Приймає перелік із бекенду і перевіряє збережений вибір за ним: підмінений
   * у localStorage ідентифікатор поза скоупом має відкотитися на дозволену
   * філію, а не лишитися в сесії (STW-02).
   */
  private applyStores(list: readonly StoreScope[]): void {
    this.scopeSignal.set(list);
    this.storage.setStores(list);
    this.restoreStoreSelection(list.map((s) => s.storeId));
  }

  private resetStores(): void {
    this.inFlight = null;
    this.scopeSignal.set(null);
    this.storage.clearStores();
  }

  private restoreStoreSelection(allowed: readonly string[]): void {
    const saved = this.selectedStoreIdSignal() ?? this.storage.getSelectedStoreId();
    const valid =
      saved && allowed.includes(saved) ? saved : (allowed[0] ?? null);
    this.selectedStoreIdSignal.set(valid);
    if (valid) {
      this.storage.setSelectedStoreId(valid);
    }
  }
}
