import type { BranchItem, CityItem, Vehicle } from '../models/models';

/** Нормалізація запиту: без регістру, без крайніх пробілів. */
export function normalizeQuery(query: string): string {
  return (query ?? '').trim().toLocaleLowerCase('uk-UA');
}

/** SUP-CITY-03: підстрочний збіг, нечутливий до регістру, від 1 символу. */
export function filterCities(
  cities: readonly CityItem[],
  query: string,
): CityItem[] {
  const needle = normalizeQuery(query);
  if (!needle) {
    return [...cities];
  }
  return cities.filter((item) =>
    item.city.toLocaleLowerCase('uk-UA').includes(needle),
  );
}

/** SUP-BR-04: пошук по адресі та externalId у межах міста. */
export function filterBranches(
  branches: readonly BranchItem[],
  query: string,
): BranchItem[] {
  const needle = normalizeQuery(query);
  if (!needle) {
    return [...branches];
  }
  return branches.filter(
    (item) =>
      item.address.toLocaleLowerCase('uk-UA').includes(needle) ||
      item.externalId.toLocaleLowerCase('uk-UA').includes(needle),
  );
}

/** SUP-BOOK-02: пошук авто за держномером (та маркою) з нормалізацією. */
export function filterVehicles(
  vehicles: readonly Vehicle[],
  query: string,
): Vehicle[] {
  const needle = normalizeQuery(query).replace(/[\s-]/g, '');
  if (!needle) {
    return [...vehicles];
  }
  return vehicles.filter(
    (vehicle) =>
      vehicle.plateNumber.toLocaleLowerCase('uk-UA').includes(needle) ||
      (vehicle.brand ?? '').toLocaleLowerCase('uk-UA').includes(needle),
  );
}
