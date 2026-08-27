import {
  DEFAULT_STORE_STATE,
  hasActiveFilters,
  storeStateFromParams,
  storeStateToParams,
  TableState,
  toggleSort,
  totalPages,
} from './query-state.util';

describe('UI-01 — стан таблиці в query-параметрах (deep-link)', () => {
  it('за замовчуванням сортує за містом за зростанням, 20 рядків', () => {
    const state = storeStateFromParams({});
    expect(state).toEqual(DEFAULT_STORE_STATE);
  });

  it('round-trip: серіалізація і розбір повертають той самий стан', () => {
    const state: TableState = {
      page: 3,
      pageSize: 50,
      sort: 'externalId',
      direction: 'desc',
      filter: {
        search: '1998',
        cities: ['Київ', 'Львів'],
        statuses: ['active', 'paused'],
        configured: false,
      },
    };
    expect(storeStateFromParams(storeStateToParams(state))).toEqual(state);
  });

  it('ігнорує некоректні значення параметрів', () => {
    const state = storeStateFromParams({
      page: '-2',
      pageSize: '37',
      dir: 'sideways',
      statuses: 'active,not_a_status',
      configured: 'maybe',
    });
    expect(state.page).toBe(1);
    expect(state.pageSize).toBe(20);
    expect(state.direction).toBe('asc');
    expect(state.filter.statuses).toEqual(['active']);
    expect(state.filter.configured).toBeNull();
  });

  it('зміна сторінки зберігає активні фільтри', () => {
    const state = storeStateFromParams({
      q: '1998',
      cities: 'Київ',
      configured: 'false',
      page: '1',
    });
    const nextParams = storeStateToParams({ ...state, page: 4 });
    const next = storeStateFromParams(nextParams);
    expect(next.page).toBe(4);
    expect(next.filter).toEqual(state.filter);
  });

  it('порожні фільтри не потрапляють у query-параметри', () => {
    const params = storeStateToParams(DEFAULT_STORE_STATE);
    expect(params['q']).toBeUndefined();
    expect(params['cities']).toBeUndefined();
    expect(params['statuses']).toBeUndefined();
    expect(params['configured']).toBeUndefined();
  });

  it('визначає наявність активних фільтрів', () => {
    expect(hasActiveFilters(DEFAULT_STORE_STATE.filter)).toBe(false);
    expect(
      hasActiveFilters({ ...DEFAULT_STORE_STATE.filter, configured: true }),
    ).toBe(true);
    expect(hasActiveFilters({ ...DEFAULT_STORE_STATE.filter, search: ' 1998 ' })).toBe(
      true,
    );
  });
});

describe('Сортування по клікабельних заголовках', () => {
  it('повторний клік по тій самій колонці перемикає напрям', () => {
    const first = toggleSort(DEFAULT_STORE_STATE, 'city');
    expect(first).toEqual({ sort: 'city', direction: 'desc', page: 1 });
  });

  it('клік по іншій колонці починає з asc і скидає сторінку', () => {
    const state: TableState = { ...DEFAULT_STORE_STATE, page: 5 };
    expect(toggleSort(state, 'externalId')).toEqual({
      sort: 'externalId',
      direction: 'asc',
      page: 1,
    });
  });

  it('рахує кількість сторінок', () => {
    expect(totalPages(0, 20)).toBe(1);
    expect(totalPages(41, 20)).toBe(3);
    expect(totalPages(100, 50)).toBe(2);
  });
});
