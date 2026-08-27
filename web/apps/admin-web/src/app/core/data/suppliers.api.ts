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
} from '../models';

export interface SupplierFilter {
  readonly search: string;
  readonly statuses: readonly SupplierStatus[];
}

export type SupplierDraft = Omit<Supplier, 'id' | 'bookingsCount'> & {
  readonly id?: string;
};

export type SupplierUserDraft = Omit<SupplierUser, 'id'> & { readonly id?: string };

/** partner-service: постачальники, їх користувачі, автопарк і водії. */
export abstract class SuppliersApi {
  abstract list(filter: SupplierFilter, query: PageQuery): Observable<Page<Supplier>>;
  abstract all(): Observable<readonly Supplier[]>;
  abstract get(id: string): Observable<Supplier>;
  abstract save(draft: SupplierDraft): Observable<Supplier>;
  abstract remove(id: string): Observable<void>;
  abstract bulkStatus(
    ids: readonly string[],
    status: SupplierStatus,
  ): Observable<readonly BulkResultRow[]>;

  abstract users(supplierId: string): Observable<readonly SupplierUser[]>;
  abstract saveUser(draft: SupplierUserDraft): Observable<SupplierUser>;
  abstract resetUserPassword(userId: string): Observable<void>;

  abstract vehicles(supplierId: string, search: string): Observable<readonly Vehicle[]>;
  abstract drivers(
    supplierId: string,
    search: string,
  ): Observable<readonly SupplierDriver[]>;
}
