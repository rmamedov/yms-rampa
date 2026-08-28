import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StorePickerComponent } from './store-picker.component';
import { StoreScope } from '../core/models/auth.model';

/**
 * Перемикач філії з пошуком. Раніше це був `<select>`: керівник мережі бачив
 * сотні філій і мусив шукати потрібну прокруткою.
 */
describe('StorePickerComponent — вибір філії з пошуком', () => {
  let fixture: ComponentFixture<StorePickerComponent>;
  let host: HTMLElement;

  const stores: StoreScope[] = [
    {
      storeId: 's-1',
      displayName: 'Сільпо на Берковецькій',
      externalId: '1932',
      city: 'Київ',
      address: 'вул. Берковецька, 6Д',
    } as StoreScope,
    {
      storeId: 's-2',
      displayName: 'Сільпо на Білоруській',
      externalId: '1995',
      city: 'Київ',
      address: 'вул. Білоруська, 2',
    } as StoreScope,
    {
      storeId: 's-3',
      displayName: 'Сільпо на Свободи',
      externalId: '2233',
      city: 'Харків',
      address: 'просп. Свободи Людвіга, 30',
    } as StoreScope,
  ];

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [StorePickerComponent] });
    fixture = TestBed.createComponent(StorePickerComponent);
    fixture.componentRef.setInput('stores', stores);
    fixture.componentRef.setInput('selectedId', 's-1');
    fixture.detectChanges();
    host = fixture.nativeElement as HTMLElement;
  });

  function trigger(): HTMLButtonElement {
    return host.querySelector('.picker__trigger') as HTMLButtonElement;
  }

  function options(): HTMLButtonElement[] {
    return Array.from(host.querySelectorAll('.picker__option'));
  }

  function search(): HTMLInputElement {
    return host.querySelector('.picker__search') as HTMLInputElement;
  }

  function type(value: string): void {
    search().value = value;
    search().dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  it('у згорнутому стані показує обрану філію', () => {
    expect(trigger().textContent).toContain('1932');
    expect(host.querySelector('.picker__panel')).toBeNull();
  });

  it('відкривається і показує всі філії', () => {
    trigger().click();
    fixture.detectChanges();
    expect(options()).toHaveLength(3);
  });

  it('шукає за кодом філії, назвою, містом і адресою', () => {
    trigger().click();
    fixture.detectChanges();

    for (const [query, expected] of [
      ['1995', 's-2'],
      ['білорус', 's-2'],
      ['Харків', 's-3'],
      ['Свободи Людвіга', 's-3'],
    ] as const) {
      type(query);
      expect(options()).toHaveLength(1);
      expect(options()[0].textContent).toContain(
        stores.find((s) => s.storeId === expected)!.externalId,
      );
    }
  });

  /** Кілька слів звужують вибірку, а не розширюють її. */
  it('усі слова запиту мають знайтися', () => {
    trigger().click();
    fixture.detectChanges();

    type('київ');
    expect(options()).toHaveLength(2);

    type('київ берков');
    expect(options()).toHaveLength(1);
    expect(options()[0].textContent).toContain('1932');
  });

  it('порожній результат показує пояснення, а не порожнечу', () => {
    trigger().click();
    fixture.detectChanges();
    type('львів');

    expect(options()).toHaveLength(0);
    expect(host.querySelector('.picker__empty')?.textContent).toContain('немає');
  });

  it('вибір філії віддає її ідентифікатор і закриває панель', () => {
    const picked: string[] = [];
    fixture.componentInstance.selected.subscribe((id: string) => picked.push(id));

    trigger().click();
    fixture.detectChanges();
    type('2233');
    options()[0].click();
    fixture.detectChanges();

    expect(picked).toEqual(['s-3']);
    expect(host.querySelector('.picker__panel')).toBeNull();
  });

  /** Перевибір тієї самої філії не має перезавантажувати дошку. */
  it('повторний вибір поточної філії події не дає', () => {
    const picked: string[] = [];
    fixture.componentInstance.selected.subscribe((id: string) => picked.push(id));

    trigger().click();
    fixture.detectChanges();
    type('1932');
    options()[0].click();
    fixture.detectChanges();

    expect(picked).toEqual([]);
  });

  it('Enter обирає підсвічену філію, Escape закриває', () => {
    const picked: string[] = [];
    fixture.componentInstance.selected.subscribe((id: string) => picked.push(id));

    trigger().click();
    fixture.detectChanges();
    type('київ');

    search().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();
    search().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();

    // стрілка вниз перевела на другу київську філію
    expect(picked).toEqual(['s-2']);

    trigger().click();
    fixture.detectChanges();
    search().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();
    expect(host.querySelector('.picker__panel')).toBeNull();
  });
});
