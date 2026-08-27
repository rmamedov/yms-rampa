/**
 * Довідник філій для мок-режиму (environment.useMocks).
 *
 * Зріз fixtures/silpo-branches.json (джерело: MCP Сільпо silpo_list_branches,
 * 2026-08-27), обрізаний до полів, які реально є у точці маршрутного листа
 * водія: місто, назва філії, адреса і КООРДИНАТИ. Координати не вигадані —
 * це `latitude`/`longitude` тієї самої філії: саме за ними застосунок будує
 * маршрут у навігаторі (DRV-21), тож у моці вони мають бути справжні.
 */
export interface MockStore {
  readonly storeName: string;
  readonly city: string;
  readonly address: string;
  readonly latitude: number;
  readonly longitude: number;
}

export const MOCK_STORES: readonly MockStore[] = [
  {
    storeName: 'Сільпо №1998',
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
    latitude: 50.5202,
    longitude: 30.51452,
  },
  {
    storeName: 'Сільпо №2025',
    city: 'Київ',
    address: 'вул. Бережанська, 22',
    latitude: 50.51869,
    longitude: 30.45616,
  },
  {
    storeName: 'Сільпо №1932',
    city: 'Київ',
    address: 'вул. Берковецька, 6Д',
    latitude: 50.49699,
    longitude: 30.36123,
  },
  {
    storeName: 'Сільпо №1990',
    city: 'Київ',
    address: 'просп. Берестейський, 87',
    latitude: 50.45669,
    longitude: 30.38344,
  },
  {
    storeName: 'Сільпо №2122',
    city: 'Львів',
    address: 'вул. Городоцька, 179',
    latitude: 49.83502,
    longitude: 23.99462,
  },
  {
    storeName: 'Сільпо №2131',
    city: 'Львів',
    address: 'просп. Червоної Калини, 62',
    latitude: 49.79188,
    longitude: 24.05784,
  },
  {
    storeName: 'Сільпо №2231',
    city: 'Харків',
    address: 'вул. Космічна, 23А',
    latitude: 50.01631,
    longitude: 36.22119,
  },
  {
    storeName: 'Сільпо №2234',
    city: 'Харків',
    address: 'просп. Тракторобудівників, 108',
    latitude: 49.99615,
    longitude: 36.34209,
  },
  {
    storeName: 'Сільпо №2142',
    city: 'Одеса',
    address: 'просп. Небесної Сотні, 2',
    latitude: 46.41623,
    longitude: 30.71254,
  },
  {
    storeName: 'Сільпо №2146',
    city: 'Одеса',
    address: 'вул. Корольова, 44',
    latitude: 46.40552,
    longitude: 30.72102,
  },
  {
    storeName: 'Сільпо №2207',
    city: 'Дніпро',
    address: 'бульв. Кельнський, 1',
    latitude: 48.46091,
    longitude: 35.05038,
  },
  {
    storeName: 'Сільпо №2679',
    city: 'Дніпро',
    address: 'вул. Лазаря Глоби, 7',
    latitude: 48.46714,
    longitude: 35.03681,
  },
  {
    storeName: 'Сільпо №2088',
    city: 'Вінниця',
    address: 'вул. Келецька, 105',
    latitude: 49.22655,
    longitude: 28.40431,
  },
  {
    storeName: 'Сільпо №3156',
    city: 'Полтава',
    address: 'вул. Європейська, 60А',
    latitude: 49.57848,
    longitude: 34.54058,
  },
  {
    storeName: 'Сільпо №2237',
    city: 'Запоріжжя',
    address: 'вул. Іванова, 1А',
    latitude: 47.83107,
    longitude: 35.18589,
  },
];
