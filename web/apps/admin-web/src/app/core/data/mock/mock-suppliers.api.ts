import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  BulkResultRow,
  Page,
  PageQuery,
  Supplier,
  SupplierDriver,
  SupplierStatus,
  SupplierUser,
  Vehicle,
} from '../../models';
import {
  SupplierDraft,
  SupplierFilter,
  SuppliersApi,
  SupplierUserDraft,
} from '../suppliers.api';
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

export function matchesSupplierFilter(
  supplier: Supplier,
  filter: SupplierFilter,
): boolean {
  if (filter.statuses.length > 0 && !filter.statuses.includes(supplier.status)) {
    return false;
  }
  const search = normalize(filter.search);
  if (search === '') {
    return true;
  }
  return (
    normalize(supplier.name).includes(search) ||
    supplier.edrpou.startsWith(search) ||
    normalize(supplier.contactPerson).includes(search)
  );
}

@Injectable()
export class MockSuppliersApi extends SuppliersApi {
  private readonly db = inject(MockDb);
  private readonly latency = inject(MOCK_LATENCY);

  list(filter: SupplierFilter, query: PageQuery): Observable<Page<Supplier>> {
    return respond(() => {
      const filtered = this.db.state.suppliers.filter((s) =>
        matchesSupplierFilter(s, filter),
      );
      const sorted = sortItems(
        filtered as unknown as Array<Record<string, unknown>>,
        query.sort ?? 'name',
        query.direction ?? 'asc',
        (a, b) => compareValues(a['name'], b['name']),
      ) as unknown as Supplier[];
      return paginate(sorted, query);
    }, this.latency);
  }

  all(): Observable<readonly Supplier[]> {
    return respond(() => [...this.db.state.suppliers], this.latency);
  }

  get(id: string): Observable<Supplier> {
    const supplier = this.db.state.suppliers.find((s) => s.id === id);
    if (!supplier) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => ({ ...supplier }), this.latency);
  }

  save(draft: SupplierDraft): Observable<Supplier> {
    const nameTaken = this.db.state.suppliers.some(
      (s) => s.id !== draft.id && normalize(s.name) === normalize(draft.name),
    );
    if (nameTaken) {
      return fail(
        422,
        { detail: 'Постачальник з такою назвою вже існує' },
        this.latency,
      );
    }
    const edrpouTaken = this.db.state.suppliers.some(
      (s) => s.id !== draft.id && s.edrpou === draft.edrpou,
    );
    if (edrpouTaken) {
      return fail(
        422,
        { detail: 'Постачальник з таким кодом ЄДРПОУ вже існує' },
        this.latency,
      );
    }
    return respond(() => {
      if (draft.id) {
        const index = this.db.state.suppliers.findIndex((s) => s.id === draft.id);
        const updated: Supplier = {
          ...this.db.state.suppliers[index],
          ...draft,
          id: draft.id,
        };
        this.db.state.suppliers[index] = updated;
        return { ...updated };
      }
      const created: Supplier = {
        ...draft,
        id: this.db.nextId('sup'),
        bookingsCount: 0,
      };
      this.db.state.suppliers = [created, ...this.db.state.suppliers];
      return { ...created };
    }, this.latency);
  }

  /** SUP-06: постачальника з історією бронювань видалити не можна. */
  remove(id: string): Observable<void> {
    const supplier = this.db.state.suppliers.find((s) => s.id === id);
    if (!supplier) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    const hasBookings = this.db.state.bookings.some((b) => b.supplierId === id);
    if (hasBookings || supplier.bookingsCount > 0) {
      return fail(
        409,
        { detail: 'Постачальника з історією бронювань не можна видалити' },
        this.latency,
      );
    }
    return respond(() => {
      this.db.state.suppliers = this.db.state.suppliers.filter((s) => s.id !== id);
      return undefined as void;
    }, this.latency);
  }

  bulkStatus(
    ids: readonly string[],
    status: SupplierStatus,
  ): Observable<readonly BulkResultRow[]> {
    return respond(
      () =>
        ids.map((id) => {
          const index = this.db.state.suppliers.findIndex((s) => s.id === id);
          if (index < 0) {
            return { id, label: id, ok: false, message: 'error.RESOURCE_NOT_FOUND' };
          }
          const supplier = this.db.state.suppliers[index];
          this.db.state.suppliers[index] = { ...supplier, status };
          return { id, label: supplier.name, ok: true };
        }),
      this.latency,
    );
  }

  users(supplierId: string): Observable<readonly SupplierUser[]> {
    return respond(
      () => this.db.state.supplierUsers.filter((u) => u.supplierId === supplierId),
      this.latency,
    );
  }

  saveUser(draft: SupplierUserDraft): Observable<SupplierUser> {
    const emailTaken = this.db.state.supplierUsers.some(
      (u) => u.id !== draft.id && normalize(u.email) === normalize(draft.email),
    );
    if (emailTaken) {
      return fail(
        422,
        { detail: 'Користувач з таким e-mail вже існує' },
        this.latency,
      );
    }
    return respond(() => {
      if (draft.id) {
        const index = this.db.state.supplierUsers.findIndex((u) => u.id === draft.id);
        const updated: SupplierUser = { ...draft, id: draft.id };
        this.db.state.supplierUsers[index] = updated;
        return { ...updated };
      }
      const created: SupplierUser = { ...draft, id: this.db.nextId('supu') };
      this.db.state.supplierUsers = [...this.db.state.supplierUsers, created];
      return { ...created };
    }, this.latency);
  }

  resetUserPassword(userId: string): Observable<void> {
    const exists = this.db.state.supplierUsers.some((u) => u.id === userId);
    if (!exists) {
      return fail(404, { code: 'RESOURCE_NOT_FOUND' }, this.latency);
    }
    return respond(() => undefined as void, this.latency);
  }

  vehicles(supplierId: string, search: string): Observable<readonly Vehicle[]> {
    return respond(() => {
      const term = normalize(search);
      return this.db.state.vehicles.filter(
        (v) =>
          v.supplierId === supplierId &&
          (term === '' || normalize(v.plate).includes(term)),
      );
    }, this.latency);
  }

  drivers(supplierId: string, search: string): Observable<readonly SupplierDriver[]> {
    return respond(() => {
      const term = normalize(search);
      return this.db.state.drivers.filter(
        (d) =>
          d.supplierId === supplierId &&
          (term === '' ||
            normalize(d.fullName).includes(term) ||
            d.phone.includes(search.trim())),
      );
    }, this.latency);
  }
}
