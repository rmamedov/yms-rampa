import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  BulkResultRow,
  Page,
  PageQuery,
  Supplier,
  SupplierStatus,
} from '../../models';
import {
  SupplierCreated,
  SupplierDraft,
  SupplierFilter,
  SuppliersApi,
} from '../suppliers.api';
import { MockDb } from './mock-db';
import {
  fail,
  MOCK_LATENCY,
  normalize,
  respond,
} from './mock-support';

const MAX_LIMIT = 200;

/**
 * Мок partner-service. Список працює на limit/offset — так само,
 * як AdminSupplierController: сортування бекенд не приймає.
 */
export function matchesSupplierFilter(
  supplier: Supplier,
  filter: SupplierFilter,
): boolean {
  if (filter.status !== null && supplier.status !== filter.status) {
    return false;
  }
  const search = normalize(filter.search);
  if (search === '') {
    return true;
  }
  return (
    normalize(supplier.name).includes(search) ||
    normalize(supplier.edrpou ?? '').includes(search)
  );
}

@Injectable()
export class MockSuppliersApi extends SuppliersApi {
  private readonly db = inject(MockDb);
  private readonly latency = inject(MOCK_LATENCY);

  list(filter: SupplierFilter, query: PageQuery): Observable<Page<Supplier>> {
    return respond(() => {
      const matched = this.db.state.suppliers.filter((s) =>
        matchesSupplierFilter(s, filter),
      );
      const limit = Math.max(1, Math.min(MAX_LIMIT, query.pageSize));
      const offset = Math.max(0, (query.page - 1) * limit);
      return {
        items: copy(matched.slice(offset, offset + limit)),
        total: matched.length,
        page: query.page,
        pageSize: limit,
      };
    }, this.latency);
  }

  all(): Observable<readonly Supplier[]> {
    return respond(
      () => copy(this.db.state.suppliers.slice(0, MAX_LIMIT)),
      this.latency,
    );
  }

  get(id: string): Observable<Supplier> {
    const supplier = this.db.state.suppliers.find((s) => s.id === id);
    if (!supplier) {
      return fail(404, { code: 'SUPPLIER_NOT_FOUND' }, this.latency);
    }
    return respond(() => copy(supplier), this.latency);
  }

  create(draft: SupplierDraft): Observable<SupplierCreated> {
    if (draft.name.trim() === '') {
      return fail(
        422,
        { code: 'SUPPLIER_NAME_REQUIRED', detail: 'Вкажіть назву постачальника.' },
        this.latency,
      );
    }
    return respond(() => {
      const supplier: Supplier = {
        id: this.db.nextId('sup'),
        name: draft.name.trim(),
        edrpou: draft.edrpou,
        status: 'active',
        statusLabel: 'Активний',
        storeAccess: {
          allStores: draft.allStores,
          storeIds: draft.allStores ? [] : [...draft.storeIds],
        },
        contacts: draft.contacts.map((c) => ({ ...c })),
        suspendedAt: null,
        suspendReason: null,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
      };
      this.db.state.suppliers = [supplier, ...this.db.state.suppliers];
      const login = draft.login?.trim() ?? '';
      return {
        supplier: copy(supplier),
        // Як і бекенд: пароль віддається один раз, а якщо його не задали —
        // генерується.
        account:
          login === ''
            ? null
            : {
                login: login.toLowerCase(),
                password: draft.password?.trim() || `Rmp${this.db.nextId('pw')}x7`,
              },
      };
    }, this.latency);
  }

  update(id: string, draft: SupplierDraft): Observable<Supplier> {
    const index = this.db.state.suppliers.findIndex((s) => s.id === id);
    if (index < 0) {
      return fail(404, { code: 'SUPPLIER_NOT_FOUND' }, this.latency);
    }
    return respond(() => {
      const current = this.db.state.suppliers[index];
      const updated: Supplier = {
        ...current,
        name: draft.name.trim(),
        edrpou: draft.edrpou,
        storeAccess: {
          allStores: draft.allStores,
          storeIds: draft.allStores ? [] : [...draft.storeIds],
        },
        contacts: draft.contacts.map((c) => ({ ...c })),
        updatedAt: new Date().toISOString(),
      };
      this.db.state.suppliers[index] = updated;
      return copy(updated);
    }, this.latency);
  }

  /** SUP-02: призупинення блокує логіни постачальника й водіїв. */
  suspend(id: string, reason: string | null): Observable<Supplier> {
    return this.setStatus(id, 'suspended', reason);
  }

  activate(id: string): Observable<Supplier> {
    return this.setStatus(id, 'active', null);
  }

  private setStatus(
    id: string,
    status: SupplierStatus,
    reason: string | null,
  ): Observable<Supplier> {
    const index = this.db.state.suppliers.findIndex((s) => s.id === id);
    if (index < 0) {
      return fail(404, { code: 'SUPPLIER_NOT_FOUND' }, this.latency);
    }
    return respond(() => {
      const updated: Supplier = {
        ...this.db.state.suppliers[index],
        status,
        statusLabel: status === 'active' ? 'Активний' : 'Призупинений',
        suspendedAt: status === 'suspended' ? new Date().toISOString() : null,
        suspendReason: status === 'suspended' ? reason : null,
        updatedAt: new Date().toISOString(),
      };
      this.db.state.suppliers[index] = updated;
      return copy(updated);
    }, this.latency);
  }

  /** SUP-06: видалення можливе лише за відсутності бронювань. */
  remove(id: string): Observable<void> {
    const index = this.db.state.suppliers.findIndex((s) => s.id === id);
    if (index < 0) {
      return fail(404, { code: 'SUPPLIER_NOT_FOUND' }, this.latency);
    }
    const hasBookings = this.db.state.bookings.some((b) => b.supplierId === id);
    if (hasBookings) {
      return fail(
        409,
        {
          code: 'SUPPLIER_HAS_BOOKINGS',
          detail: 'Постачальника не можна видалити: є бронювання.',
        },
        this.latency,
      );
    }
    return respond(() => {
      this.db.state.suppliers = this.db.state.suppliers.filter((s) => s.id !== id);
      return undefined;
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
            return { id, label: id, ok: false, message: 'Постачальника не знайдено' };
          }
          const current = this.db.state.suppliers[index];
          this.db.state.suppliers[index] = {
            ...current,
            status,
            statusLabel: status === 'active' ? 'Активний' : 'Призупинений',
            suspendedAt: status === 'suspended' ? new Date().toISOString() : null,
            updatedAt: new Date().toISOString(),
          };
          return { id, label: current.name, ok: true };
        }),
      this.latency,
    );
  }
}

function copy<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}
