import { computed, inject, Injectable, signal } from '@angular/core';
import { map, Observable, tap, throwError } from 'rxjs';
import {
  AuthTokens,
  LoginRequest,
  StaffProfile,
  STORE_ROLES,
  StoreScope,
} from '../models/auth.model';
import { AppError } from '../models/problem.model';
import { StoreConfig } from '../models/store.model';
import { AuthGateway } from '../data/gateways';
import { TokenStorageService } from './token-storage.service';

/**
 * Сесія staff-контуру: логін, refresh, RBAC-перевірка ролі (STW-01)
 * та вибір поточного магазину (STW-03).
 *
 * Бекенд у профілі віддає лише `scope.storeIds` — описових назв філій у
 * контурі магазину немає. Тому назви беруться з конфігурації магазину, коли
 * та завантажена, а до того показується ідентифікатор.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly gateway = inject(AuthGateway);
  private readonly storage = inject(TokenStorageService);

  private readonly profileSignal = signal<StaffProfile | null>(
    this.storage.getProfile(),
  );
  private readonly selectedStoreIdSignal = signal<string | null>(
    this.storage.getSelectedStoreId(),
  );
  private readonly labelsSignal = signal<Readonly<Record<string, StoreScope>>>({});

  readonly profile = this.profileSignal.asReadonly();

  readonly isAuthenticated = computed(() => this.profileSignal() !== null);

  /** STW-01: доступ лише для store_manager / store_operator. */
  readonly hasStoreAccess = computed(() => {
    const profile = this.profileSignal();
    return profile !== null && STORE_ROLES.includes(profile.role);
  });

  readonly stores = computed<readonly StoreScope[]>(() => {
    const labels = this.labelsSignal();
    return (this.profileSignal()?.storeIds ?? []).map(
      (storeId) =>
        labels[storeId] ?? {
          storeId,
          displayName: storeId,
          externalId: null,
          city: null,
          address: null,
        },
    );
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
        this.restoreStoreSelection(response.profile);
        return response.profile;
      }),
    );
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

  private restoreStoreSelection(profile: StaffProfile): void {
    const saved = this.storage.getSelectedStoreId();
    const valid =
      saved && profile.storeIds.includes(saved)
        ? saved
        : (profile.storeIds[0] ?? null);
    this.selectedStoreIdSignal.set(valid);
    if (valid) {
      this.storage.setSelectedStoreId(valid);
    }
  }
}
