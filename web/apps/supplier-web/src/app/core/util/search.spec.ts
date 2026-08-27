import { filterBranches, filterCities, filterVehicles } from './search';
import type { BranchItem, CityItem, Vehicle } from '../models/models';

const cities: CityItem[] = [
  { city: 'Київ', storeCount: 118 },
  { city: 'Кривий Ріг', storeCount: 6 },
  { city: 'Львів', storeCount: 31 },
];

const branches: BranchItem[] = [
  {
    storeId: 's1',
    externalId: '1998',
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
    maxVehicleWeightTons: 20,
    hasFreeSlots: true,
    ymsStatus: 'active',
  },
  {
    storeId: 's2',
    externalId: '2025',
    city: 'Київ',
    address: 'вул. Бережанська, 22',
    maxVehicleWeightTons: 10,
    hasFreeSlots: false,
    ymsStatus: 'active',
  },
];

const vehicles: Vehicle[] = [
  { id: 'v1', plateNumber: 'АА1234ВС', brand: 'Renault', weightTons: 3.5, active: true },
  { id: 'v2', plateNumber: 'ВІ5678КМ', brand: 'MAN', weightTons: 20, active: true },
];

describe('пошук у довідниках (SUP-CITY-03, SUP-BR-04, SUP-BOOK-02)', () => {
  it('шукає місто підстрочно, без урахування регістру, від 1 символу', () => {
    expect(filterCities(cities, 'к').map((c) => c.city)).toEqual([
      'Київ',
      'Кривий Ріг',
    ]);
    expect(filterCities(cities, 'ЛЬВ').map((c) => c.city)).toEqual(['Львів']);
    expect(filterCities(cities, 'нічого')).toEqual([]);
    expect(filterCities(cities, '  ')).toHaveLength(3);
  });

  it('шукає філію за адресою та номером філії', () => {
    expect(filterBranches(branches, 'бережан').map((b) => b.externalId)).toEqual(
      ['2025'],
    );
    expect(filterBranches(branches, '1998').map((b) => b.storeId)).toEqual([
      's1',
    ]);
    expect(filterBranches(branches, 'відсутнє')).toEqual([]);
  });

  it('шукає авто за держномером із нормалізацією пробілів', () => {
    expect(filterVehicles(vehicles, 'аа 1234').map((v) => v.id)).toEqual(['v1']);
    expect(filterVehicles(vehicles, 'man').map((v) => v.id)).toEqual(['v2']);
  });
});
