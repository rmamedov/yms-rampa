import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { Page, PageQuery, StaffUser } from '../../models';
import { StaffApi, StaffFilter, StaffUserDraft } from '../staff.api';
import { MockDb } from './mock-db';
import {
  compareValues,
  fail,
  MOCK_LATENCY,
  normalize,
  paginate,
  respond,
  sortItems,
} from './mock-support';
import { ROLES_REQUIRING_STORES } from '../../rbac/permissions';

export function matchesStaffFilter(user: StaffUser, filter: StaffFilter): boolean {
  if (filter.roles.length > 0 && !filter.roles.includes(user.role)) {
    return false;
  }
  if (filter.active !== null && user.active !== filter.active) {
    return false;
  }
  const search = normalize(filter.search);
  if (search === '') {
    return true;
  }
  return (
    normalize(user.fullName).includes(search) ||
    normalize(user.email).includes(search) ||
    user.phone.includes(filter.search.trim())
  );
}

@Injectable()
export class MockStaffApi extends StaffApi {
  private readonly db = inject(MockDb);
  private readonly latency = inject(MOCK_LATENCY);

  list(filter: StaffFilter, query: PageQuery): Observable<Page<StaffUser>> {
    return respond(() => {
      const filtered = this.db.state.staff.filter((u) =>
        matchesStaffFilter(u, filter),
      );
      const sorted = sortItems(
        filtered as unknown as Array<Record<string, unknown>>,
        query.sort ?? 'fullName',
        query.direction ?? 'asc',
        (a, b) => compareValues(a['fullName'], b['fullName']),
      ) as unknown as StaffUser[];
      return paginate(sorted, query);
    }, this.latency);
  }

  get(id: string): Observable<StaffUser> {
    const user = this.db.state.staff.find((u) => u.id === id);
    if (!user) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => ({ ...user }), this.latency);
  }

  save(draft: StaffUserDraft, actorId: string): Observable<StaffUser> {
    // USR-02: для store_manager / store_operator привʼязка ≥1 магазину обовʼязкова
    if (
      (ROLES_REQUIRING_STORES as readonly string[]).includes(draft.role) &&
      draft.storeIds.length === 0
    ) {
      return fail(
        422,
        { detail: 'Для цієї ролі потрібно вказати щонайменше один магазин' },
        this.latency,
      );
    }
    const emailTaken = this.db.state.staff.some(
      (u) => u.id !== draft.id && normalize(u.email) === normalize(draft.email),
    );
    if (emailTaken) {
      return fail(
        422,
        { detail: 'Користувач з таким e-mail вже існує' },
        this.latency,
      );
    }
    // RBAC-24: користувач не може змінювати власну роль
    if (draft.id && draft.id === actorId) {
      const current = this.db.state.staff.find((u) => u.id === draft.id);
      if (current && current.role !== draft.role) {
        return fail(
          403,
          { code: 'RBAC_SELF_ROLE_CHANGE_FORBIDDEN' },
          this.latency,
        );
      }
    }
    return respond(() => {
      if (draft.id) {
        const index = this.db.state.staff.findIndex((u) => u.id === draft.id);
        const updated: StaffUser = { ...draft, id: draft.id };
        this.db.state.staff[index] = updated;
        return { ...updated };
      }
      const created: StaffUser = { ...draft, id: this.db.nextId('su') };
      this.db.state.staff = [...this.db.state.staff, created];
      return { ...created };
    }, this.latency);
  }

  setActive(id: string, active: boolean, actorId: string): Observable<StaffUser> {
    const index = this.db.state.staff.findIndex((u) => u.id === id);
    if (index < 0) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    // USR-03 / RBAC-24: не можна деактивувати самого себе
    if (!active && id === actorId) {
      return fail(403, { code: 'RBAC_SELF_ROLE_CHANGE_FORBIDDEN' }, this.latency);
    }
    const user = this.db.state.staff[index];
    // RBAC-25: має лишитись щонайменше один активний super_admin
    if (!active && user.role === 'super_admin') {
      const activeSupers = this.db.state.staff.filter(
        (u) => u.role === 'super_admin' && u.active,
      );
      if (activeSupers.length <= 1) {
        return fail(409, { code: 'RBAC_LAST_SUPER_ADMIN' }, this.latency);
      }
    }
    return respond(() => {
      const updated: StaffUser = { ...user, active };
      this.db.state.staff[index] = updated;
      return { ...updated };
    }, this.latency);
  }
}
