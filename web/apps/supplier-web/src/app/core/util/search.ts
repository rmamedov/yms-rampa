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
  // Держномер шукаємо без роздільників, щоб «AA 1234 BC» знаходило «AA1234BC».
  // Марку — за початковим запитом: раніше вона порівнювалася з очищеним від
  // пробілів рядком, тому будь-який запит із пробілом («Renault 8512») не
  // знаходив нічого.
  const raw = normalizeQuery(query);
  const needle = raw.replace(/[\s-]/g, '');
  if (!needle) {
    return [...vehicles];
  }
  return vehicles.filter(
    (vehicle) =>
      vehicle.plateNumber.toLocaleLowerCase('uk-UA').replace(/[\s-]/g, '').includes(needle) ||
      (vehicle.brand ?? '').toLocaleLowerCase('uk-UA').includes(raw),
  );
}
