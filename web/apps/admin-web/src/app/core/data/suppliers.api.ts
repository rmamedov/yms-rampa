import { Observable } from 'rxjs';
import {
  BulkResultRow,
  Page,
  PageQuery,
  Supplier,
  SupplierContact,
  SupplierStatus,
} from '../models';

export interface SupplierFilter {
  readonly search: string;
  /** AdminSupplierController приймає ОДИН статус (?status=), не перелік. */
  readonly status: SupplierStatus | null;
}

/** Тіло POST/PATCH /api/admin/v1/suppliers[/{id}] (SUP-01, SUP-03). */
export interface SupplierDraft {
  readonly name: string;
  readonly edrpou: string | null;
  readonly allStores: boolean;
  readonly storeIds: readonly string[];
  readonly contacts: readonly SupplierContact[];
  /**
   * Пошта для входу в кабінет. Необовʼязкова: контрагента можна завести без
   * доступу, а видати його пізніше. Надсилається лише при СТВОРЕННІ.
   */
  readonly login?: string | null;
  /** Порожній — систему попросять згенерувати пароль (AUTH-24). */
  readonly password?: string | null;
}

/** Створений постачальник разом із доступом, якщо його заводили. */
export interface SupplierCreated {
  readonly supplier: Supplier;
  readonly account: {
    readonly login: string;
    readonly password: string;
  } | null;
}

/** partner-service, адмін-контур постачальників. */
export abstract class SuppliersApi {
  /** GET /suppliers?q&status&limit&offset */
  abstract list(filter: SupplierFilter, query: PageQuery): Observable<Page<Supplier>>;
  /** Довідник для селектів — одна сторінка максимального розміру. */
  abstract all(): Observable<readonly Supplier[]>;
  abstract get(id: string): Observable<Supplier>;
  /** Пароль у відповіді приходить РІВНО ОДИН РАЗ — далі лише перегенерація. */
  abstract create(draft: SupplierDraft): Observable<SupplierCreated>;
  abstract update(id: string, draft: SupplierDraft): Observable<Supplier>;
  /** SUP-02: POST /suppliers/{id}/suspend */
  abstract suspend(id: string, reason: string | null): Observable<Supplier>;
  abstract activate(id: string): Observable<Supplier>;
  /** SUP-06: 409 SUPPLIER_HAS_BOOKINGS за наявності бронювань. */
  abstract remove(id: string): Observable<void>;

  /**
   * Масового маршруту бекенд не має — збирається з suspend/activate
   * по кожному постачальнику.
   */
  abstract bulkStatus(
    ids: readonly string[],
    status: SupplierStatus,
  ): Observable<readonly BulkResultRow[]>;
}
