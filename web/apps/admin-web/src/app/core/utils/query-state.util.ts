import { Params } from '@angular/router';
import {
  PAGE_SIZES,
  PageQuery,
  PageSize,
  SortDirection,
  StoreListFilter,
  YmsStatus,
  YMS_STATUSES,
} from '../models';

/**
 * UI-01: стан фільтрів/пагінації/сортування зберігається в query-параметрах URL (deep-link).
 */

export interface TableState extends PageQuery {
  readonly filter: StoreListFilter;
}

export const DEFAULT_STORE_FILTER: StoreListFilter = {
  search: '',
  cities: [],
  statuses: [],
  configured: null,
};

/** STL-05: за замовчуванням сортування за містом, потім за externalId. */
export const DEFAULT_STORE_STATE: TableState = {
  page: 1,
  pageSize: 20,
  sort: 'city',
  direction: 'asc',
  filter: DEFAULT_STORE_FILTER,
};

function parseList(value: unknown): string[] {
  if (typeof value !== 'string' || value.trim() === '') {
    return [];
  }
  return value
    .split(',')
    .map((v) => v.trim())
    .filter((v) => v.length > 0);
}

function parsePageSize(value: unknown): PageSize {
  const num = Number(value);
  return (PAGE_SIZES as readonly number[]).includes(num)
    ? (num as PageSize)
    : DEFAULT_STORE_STATE.pageSize;
}

function parseDirection(value: unknown): SortDirection {
  return value === 'desc' ? 'desc' : 'asc';
}

function parseConfigured(value: unknown): boolean | null {
  if (value === 'true') return true;
  if (value === 'false') return false;
  return null;
}

export function storeStateFromParams(params: Params): TableState {
  const page = Number(params['page']);
  return {
    page: Number.isInteger(page) && page > 0 ? page : 1,
    pageSize: parsePageSize(params['pageSize']),
    sort: typeof params['sort'] === 'string' ? params['sort'] : DEFAULT_STORE_STATE.sort,
    direction: parseDirection(params['dir']),
    filter: {
      search: typeof params['q'] === 'string' ? params['q'] : '',
      cities: parseList(params['cities']),
      statuses: parseList(params['statuses']).filter((s): s is YmsStatus =>
        (YMS_STATUSES as readonly string[]).includes(s),
      ),
      configured: parseConfigured(params['configured']),
    },
  };
}

export function storeStateToParams(state: TableState): Params {
  const params: Params = {
    page: state.page,
    pageSize: state.pageSize,
  };
  if (state.sort) params['sort'] = state.sort;
  if (state.direction) params['dir'] = state.direction;
  if (state.filter.search) params['q'] = state.filter.search;
  if (state.filter.cities.length) params['cities'] = state.filter.cities.join(',');
  if (state.filter.statuses.length)
    params['statuses'] = state.filter.statuses.join(',');
  if (state.filter.configured !== null)
    params['configured'] = String(state.filter.configured);
  return params;
}

export function hasActiveFilters(filter: StoreListFilter): boolean {
  return (
    filter.search.trim().length > 0 ||
    filter.cities.length > 0 ||
    filter.statuses.length > 0 ||
    filter.configured !== null
  );
}

/** Клік по заголовку: та сама колонка — перемикає напрям, інша — asc. */
export function toggleSort(
  state: TableState,
  column: string,
): Pick<TableState, 'sort' | 'direction' | 'page'> {
  if (state.sort === column) {
    return {
      sort: column,
      direction: state.direction === 'asc' ? 'desc' : 'asc',
      page: 1,
    };
  }
  return { sort: column, direction: 'asc', page: 1 };
}

export function totalPages(total: number, pageSize: number): number {
  return Math.max(1, Math.ceil(total / Math.max(1, pageSize)));
}
