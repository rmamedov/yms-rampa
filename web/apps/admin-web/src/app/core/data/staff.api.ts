import { Observable } from 'rxjs';
import { Page, PageQuery, StaffRole, StaffUser } from '../models';

export interface StaffFilter {
  readonly search: string;
  readonly roles: readonly StaffRole[];
  readonly active: boolean | null;
}

export type StaffUserDraft = Omit<StaffUser, 'id'> & { readonly id?: string };

/** identity-staff-service: CRUD staff-користувачів (5.5). */
export abstract class StaffApi {
  abstract list(filter: StaffFilter, query: PageQuery): Observable<Page<StaffUser>>;
  abstract get(id: string): Observable<StaffUser>;
  abstract save(draft: StaffUserDraft, actorId: string): Observable<StaffUser>;
  abstract setActive(
    id: string,
    active: boolean,
    actorId: string,
  ): Observable<StaffUser>;
}
