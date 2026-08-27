import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  Page,
  PageQuery,
  StaffRole,
  StaffUser,
  StaffUserCredentials,
} from '../../models';
import { ROLES_REQUIRING_STORES, STAFF_ROLES } from '../../rbac/permissions';
import {
  StaffUserDraft,
  StaffUserFilter,
  StaffUserPatch,
  UsersApi,
} from '../users.api';
import { MockAccount, MockDb } from './mock-db';
import {
  fail,
  isAllowedPerPage,
  MOCK_LATENCY,
  normalize,
  paginate,
  PER_PAGE_PROBLEM,
  respond,
} from './mock-support';

const ROLE_LABELS: Readonly<Record<StaffRole, string>> = {
  super_admin: 'Суперадміністратор',
  network_manager: 'Менеджер мережі',
  store_manager: 'Керівник магазину',
  store_operator: 'Приймальник магазину',
  analyst: 'Аналітик',
};

const ZERO_ACCESS_WARNING =
  'Магазини не привʼязані: користувач не матиме доступу до жодного магазину.';

/** AUTH-61: мок теж показує пароль один раз — і теж його не зберігає. */
const PASSWORD_NOTICE = 'Запишіть пароль — повторно він не показується.';

const PASSWORD_ALPHABET =
  'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

/**
 * RBAC-13: порожній перелік магазинів для магазинних ролей = НУЛЬ доступу.
 * Ознака рахується тут так само, як у StaffUserView::scope() бекенду.
 */
export function toStaffUser(account: MockAccount): StaffUser {
  const storeScoped = (ROLES_REQUIRING_STORES as readonly string[]).includes(
    account.role,
  );
  const zeroAccess = storeScoped && account.storeIds.length === 0;

  return {
    id: account.id,
    email: account.email,
    fullName: account.fullName,
    role: account.role,
    roleLabel: account.roleLabel,
    scope: {
      storeIds: [...account.storeIds],
      networkWide: account.networkWide,
      storeScoped,
      zeroAccess,
      warning: zeroAccess ? ZERO_ACCESS_WARNING : null,
    },
    active: account.active,
    twoFactorEnabled: account.twoFactorEnabled,
    lastLoginAt: account.lastLoginAt,
    createdAt: account.createdAt,
    updatedAt: account.updatedAt,
  };
}

export function matchesUserFilter(
  account: MockAccount,
  filter: StaffUserFilter,
): boolean {
  if (filter.role !== '' && account.role !== filter.role) {
    return false;
  }
  if (filter.status === 'active' && !account.active) {
    return false;
  }
  if (filter.status === 'inactive' && account.active) {
    return false;
  }
  const search = normalize(filter.search);
  if (search === '') {
    return true;
  }
  return (
    normalize(account.email).includes(search) ||
    normalize(account.fullName).includes(search)
  );
}

/**
 * Мок identity-staff-service, розділ «Користувачі» (4.7).
 *
 * Перевіряє те саме, що й бекенд на рівні формату: рівно одна відома роль,
 * унікальний e-mail, обовʼязкові поля, дозволений розмір сторінки. Правила
 * розмежування прав (дерево 4.7, RBAC-24/25) лишаються за бекендом — у мока
 * немає поняття актора, як і в MockSuppliersApi.
 */
@Injectable()
export class MockUsersApi extends UsersApi {
  private readonly db = inject(MockDb);
  private readonly latency = inject(MOCK_LATENCY);

  list(filter: StaffUserFilter, query: PageQuery): Observable<Page<StaffUser>> {
    if (!isAllowedPerPage(query.pageSize)) {
      return fail(422, PER_PAGE_PROBLEM, this.latency);
    }
    return respond(() => {
      // Активні першими, далі за e-mail — той самий порядок, що й у сховищі.
      const matched = [...this.db.state.accounts]
        .filter((a) => matchesUserFilter(a, filter))
        .sort((a, b) =>
          a.active === b.active
            ? a.email.localeCompare(b.email, 'uk')
            : Number(b.active) - Number(a.active),
        )
        .map(toStaffUser);

      return paginate(matched, query, 'Користувачів за заданими умовами не знайдено');
    }, this.latency);
  }

  get(id: string): Observable<StaffUser> {
    const account = this.find(id);
    if (!account) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => toStaffUser(account), this.latency);
  }

  create(draft: StaffUserDraft): Observable<StaffUserCredentials> {
    const invalid = this.validate(draft);
    if (invalid) {
      return invalid;
    }

    return respond(() => {
      const storeScoped = (ROLES_REQUIRING_STORES as readonly string[]).includes(
        draft.role,
      );
      const now = new Date().toISOString();
      const account: MockAccount = {
        id: this.db.nextId('su'),
        email: normalize(draft.email),
        fullName: draft.fullName.trim(),
        role: draft.role,
        roleLabel: ROLE_LABELS[draft.role],
        active: true,
        networkWide: !storeScoped,
        storeIds: storeScoped ? [...draft.storeIds] : [],
        twoFactorEnabled: false,
        lastLoginAt: null,
        createdAt: now,
        updatedAt: now,
      };
      this.db.state.accounts = [account, ...this.db.state.accounts];

      return this.credentials(account, draft.password);
    }, this.latency);
  }

  update(id: string, patch: StaffUserPatch): Observable<StaffUser> {
    const index = this.db.state.accounts.findIndex((a) => a.id === id);
    if (index < 0) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    if (patch.role !== undefined && !this.isKnownRole(patch.role)) {
      return fail(
        422,
        { code: 'VALIDATION_FAILED', detail: 'Невідома роль.' },
        this.latency,
      );
    }
    if (patch.fullName !== undefined && patch.fullName.trim() === '') {
      return fail(
        422,
        { code: 'VALIDATION_FAILED', detail: 'Вкажіть повне імʼя.' },
        this.latency,
      );
    }

    return respond(() => {
      const current = this.db.state.accounts[index];
      const role = patch.role ?? current.role;
      const storeScoped = (ROLES_REQUIRING_STORES as readonly string[]).includes(role);
      const storeIds = patch.storeIds ?? current.storeIds;

      const updated: MockAccount = {
        ...current,
        role,
        roleLabel: ROLE_LABELS[role],
        fullName: patch.fullName?.trim() ?? current.fullName,
        networkWide: !storeScoped,
        // Мережевій ролі перелік магазинів ні на що не впливає — не зберігаємо.
        storeIds: storeScoped ? [...storeIds] : [],
        updatedAt: new Date().toISOString(),
      };
      this.db.state.accounts[index] = updated;

      return toStaffUser(updated);
    }, this.latency);
  }

  deactivate(id: string): Observable<StaffUser> {
    return this.setActive(id, false);
  }

  activate(id: string): Observable<StaffUser> {
    return this.setActive(id, true);
  }

  resetPassword(id: string): Observable<StaffUserCredentials> {
    const account = this.find(id);
    if (!account) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => this.credentials(account, null), this.latency);
  }

  private setActive(id: string, active: boolean): Observable<StaffUser> {
    const index = this.db.state.accounts.findIndex((a) => a.id === id);
    if (index < 0) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    // RBAC-25: останнього активного super_admin деактивувати не можна.
    if (
      !active &&
      this.db.state.accounts[index].role === 'super_admin' &&
      this.db.state.accounts.filter((a) => a.role === 'super_admin' && a.active)
        .length <= 1
    ) {
      return fail(
        409,
        {
          code: 'RBAC_LAST_SUPER_ADMIN',
          detail: 'Останнього адміністратора деактивувати не можна',
        },
        this.latency,
      );
    }
    return respond(() => {
      const updated: MockAccount = {
        ...this.db.state.accounts[index],
        active,
        updatedAt: new Date().toISOString(),
      };
      this.db.state.accounts[index] = updated;
      return toStaffUser(updated);
    }, this.latency);
  }

  private validate(draft: StaffUserDraft): Observable<never> | null {
    if (normalize(draft.email) === '') {
      return fail(
        422,
        { code: 'VALIDATION_FAILED', detail: 'Вкажіть e-mail.' },
        this.latency,
      );
    }
    if (draft.fullName.trim() === '') {
      return fail(
        422,
        { code: 'VALIDATION_FAILED', detail: 'Вкажіть повне імʼя.' },
        this.latency,
      );
    }
    if (!this.isKnownRole(draft.role)) {
      return fail(
        422,
        { code: 'VALIDATION_FAILED', detail: 'Невідома роль.' },
        this.latency,
      );
    }
    if (
      this.db.state.accounts.some((a) => normalize(a.email) === normalize(draft.email))
    ) {
      return fail(
        409,
        {
          code: 'USER_EMAIL_ALREADY_EXISTS',
          detail: 'Користувач з таким email вже існує.',
        },
        this.latency,
      );
    }
    return null;
  }

  private isKnownRole(role: string): role is StaffRole {
    return (STAFF_ROLES as readonly string[]).includes(role);
  }

  private find(id: string): MockAccount | undefined {
    return this.db.state.accounts.find((a) => a.id === id);
  }

  private credentials(
    account: MockAccount,
    explicit: string | null,
  ): StaffUserCredentials {
    return {
      user: toStaffUser(account),
      login: account.email,
      password: explicit ?? generatePassword(),
      passwordGenerated: explicit === null,
      passwordNotice: PASSWORD_NOTICE,
    };
  }
}

/** Довжина 16 — як у SecurePasswordGenerator бекенду. */
function generatePassword(): string {
  let password = '';
  for (let i = 0; i < 16; i += 1) {
    password += PASSWORD_ALPHABET[Math.floor(Math.random() * PASSWORD_ALPHABET.length)];
  }
  return password;
}
