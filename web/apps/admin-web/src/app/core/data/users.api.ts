import { Observable } from 'rxjs';
import {
  Page,
  PageQuery,
  StaffRole,
  StaffUser,
  StaffUserCredentials,
  StaffUserStatusFilter,
} from '../models';

export interface StaffUserFilter {
  /** Пошук за e-mail або повним імʼям (одне поле, як на бекенді). */
  readonly search: string;
  /** AdminUserController приймає ОДНУ роль (?role=), не перелік. */
  readonly role: StaffRole | '';
  /** '' — будь-який статус. */
  readonly status: StaffUserStatusFilter;
}

/**
 * Тіло POST /api/admin/v1/users.
 *
 * `password: null` — «згенеруй сам»: бекенд поверне пароль один раз
 * і більше ніколи (AUTH-61).
 */
export interface StaffUserDraft {
  readonly email: string;
  readonly fullName: string;
  /** RBAC-04: рівно одна роль. */
  readonly role: StaffRole;
  /** RBAC-13: має сенс лише для магазинних ролей. */
  readonly storeIds: readonly string[];
  readonly password: string | null;
}

/** Тіло PATCH /api/admin/v1/users/{id} — застосовуються лише передані поля. */
export interface StaffUserPatch {
  readonly fullName?: string;
  readonly role?: StaffRole;
  readonly storeIds?: readonly string[];
}

/** identity-staff-service, розділ «Користувачі» адмін-панелі (4.7). */
export abstract class UsersApi {
  /** GET /users?q&role&status&page&perPage */
  abstract list(
    filter: StaffUserFilter,
    query: PageQuery,
  ): Observable<Page<StaffUser>>;

  abstract get(id: string): Observable<StaffUser>;

  /** POST /users — відповідь містить одноразовий пароль. */
  abstract create(draft: StaffUserDraft): Observable<StaffUserCredentials>;

  abstract update(id: string, patch: StaffUserPatch): Observable<StaffUser>;

  /** RBAC-24/25: себе і останнього super_admin деактивувати не можна. */
  abstract deactivate(id: string): Observable<StaffUser>;
  abstract activate(id: string): Observable<StaffUser>;

  /** POST /users/{id}/password/reset — одноразовий показ нового пароля. */
  abstract resetPassword(id: string): Observable<StaffUserCredentials>;
}
