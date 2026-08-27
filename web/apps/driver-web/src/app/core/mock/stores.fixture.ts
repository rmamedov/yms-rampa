/**
 * Довідник філій для мок-режиму (environment.useMocks).
 *
 * Зріз fixtures/silpo-branches.json (джерело: MCP Сільпо silpo_list_branches,
 * 2026-08-27), обрізаний до полів, які реально є у точці маршрутного листа
 * водія: місто, назва філії, адреса. Координат бекенд у цьому контурі
 * не віддає, тому їх тут немає.
 */
export interface MockStore {
  readonly storeName: string;
  readonly city: string;
  readonly address: string;
}

export const MOCK_STORES: readonly MockStore[] = [
  {
    storeName: 'Сільпо №1998',
    city: 'Київ',
    address: 'просп. Володимира Івасюка, 46',
  },
  { storeName: 'Сільпо №2025', city: 'Київ', address: 'вул. Бережанська, 22' },
  { storeName: 'Сільпо №3319', city: 'Київ', address: 'вул. Здолбунівська, 17' },
  { storeName: 'Сільпо №1042', city: 'Київ', address: 'просп. Оболонський, 21б' },
  { storeName: 'Сільпо №2711', city: 'Львів', address: 'вул. Городоцька, 179' },
  { storeName: 'Сільпо №3155', city: 'Львів', address: 'просп. Червоної Калини, 62' },
  { storeName: 'Сільпо №1866', city: 'Харків', address: 'вул. Академіка Павлова, 44б' },
  { storeName: 'Сільпо №2390', city: 'Харків', address: 'просп. Науки, 9' },
  { storeName: 'Сільпо №1477', city: 'Одеса', address: 'вул. Генуезька, 24б' },
  { storeName: 'Сільпо №2088', city: 'Одеса', address: 'просп. Небесної Сотні, 2' },
  { storeName: 'Сільпо №3502', city: 'Дніпро', address: 'вул. Космічна, 2' },
  { storeName: 'Сільпо №1720', city: 'Дніпро', address: 'просп. Дмитра Яворницького, 50' },
  { storeName: 'Сільпо №2644', city: 'Вінниця', address: 'вул. Пирогова, 137' },
  { storeName: 'Сільпо №1315', city: 'Полтава', address: 'вул. Європейська, 60' },
  { storeName: 'Сільпо №2901', city: 'Запоріжжя', address: 'просп. Соборний, 176' },
];
