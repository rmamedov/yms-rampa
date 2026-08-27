import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  ActivatedRoute,
  ParamMap,
  convertToParamMap,
  provideRouter,
} from '@angular/router';
import { BehaviorSubject, firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { AuthApi } from '../../core/data/auth.api';
import { MockAuthApi } from '../../core/data/mock/mock-auth.api';
import { MockDb, MockStore } from '../../core/data/mock/mock-db';
import { MockStoresApi } from '../../core/data/mock/mock-stores.api';
import { MockSuppliersApi } from '../../core/data/mock/mock-suppliers.api';
import { MOCK_LATENCY } from '../../core/data/mock/mock-support';
import { StoresApi } from '../../core/data/stores.api';
import { SuppliersApi } from '../../core/data/suppliers.api';
import { StoreDetailPage } from './store-detail.page';

function buttonWithText(scope: Element, text: string): HTMLButtonElement {
  const button = Array.from(scope.querySelectorAll('button')).find((b) =>
    (b.textContent ?? '').includes(text),
  );
  if (!button) {
    throw new Error(`Кнопки «${text}» немає в розмітці`);
  }
  return button;
}

function required<T>(value: T | null | undefined, what: string): T {
  if (value === null || value === undefined) {
    throw new Error(`${what} немає в розмітці`);
  }
  return value;
}

describe('Картка магазину — збереження конфігурації', () => {
  let fixture: ComponentFixture<StoreDetailPage>;
  let host: HTMLElement;
  let db: MockDb;
  let store: MockStore;
  let paramMap: BehaviorSubject<ParamMap>;

  /** Готує пісочницю і вибирає налаштований магазин, ще не рендерячи сторінку. */
  async function setup(): Promise<void> {
    localStorage.clear();
    paramMap = new BehaviorSubject<ParamMap>(convertToParamMap({}));
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: StoresApi, useClass: MockStoresApi },
        { provide: SuppliersApi, useClass: MockSuppliersApi },
        { provide: MOCK_LATENCY, useValue: 0 },
        { provide: ActivatedRoute, useValue: { paramMap } },
      ],
    });
    db = TestBed.inject(MockDb);
    db.reset();
    store = required(
      db.state.stores.find((s) => s.configurations.length > 0),
      'Налаштованого магазину в пісочниці',
    );
    // ADM-05: конфігурацію редагує super_admin.
    await firstValueFrom(
      TestBed.inject(AuthService).login('super.admin@silpo.ua', 'demo'),
    );
    paramMap.next(convertToParamMap({ id: store.card.id }));
  }

  function render(): void {
    fixture = TestBed.createComponent(StoreDetailPage);
    fixture.detectChanges();
    host = fixture.nativeElement as HTMLElement;
  }

  function openTab(label: string): void {
    buttonWithText(required(host.querySelector('.tabs'), 'Вкладок'), label).click();
    fixture.detectChanges();
  }

  /** На вкладці «Прийом поставок» є ще одна .btn-primary — «Додати виняток». */
  function saveButton(): HTMLButtonElement {
    return required(
      Array.from(host.querySelectorAll<HTMLButtonElement>('button.btn-primary')).find(
        (b) => (b.textContent ?? '').includes('Зберегти'),
      ),
      'Кнопки «Зберегти»',
    );
  }

  function rampsTable(): Element {
    return required(host.querySelector('table.data'), 'Таблиці рамп');
  }

  function deleteButtons(): HTMLButtonElement[] {
    return Array.from(rampsTable().querySelectorAll('button')).filter((b) =>
      (b.textContent ?? '').includes('Видалити'),
    );
  }

  afterEach(() => localStorage.clear());

  it('STC-21: без жодної рампи «Зберегти» заблоковано', async () => {
    await setup();
    render();
    openTab('Слоти');
    expect(deleteButtons().length).toBeGreaterThan(0);

    for (let guard = 0; deleteButtons().length > 0; guard += 1) {
      if (guard > 20) {
        throw new Error('Рампи не видаляються');
      }
      deleteButtons()[0].click();
      fixture.detectChanges();
    }

    expect(host.textContent).toContain('Потрібна щонайменше одна рампа');
    expect(saveButton().disabled).toBe(true);
  });

  it('STC-11: зламаний інтервал прийому блокує «Зберегти»', async () => {
    await setup();
    render();
    openTab('Прийом поставок');

    const monday = host.querySelectorAll('.day-row')[0];
    const times = monday.querySelectorAll<HTMLInputElement>('input[type=time]');
    times[0].value = '18:00';
    times[0].dispatchEvent(new Event('input'));
    times[1].value = '08:00';
    times[1].dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(host.textContent).toContain('Початок має бути раніше за кінець');
    expect(saveButton().disabled).toBe(true);
  });

  it('коректна зміна конфігурації «Зберегти» не блокує', async () => {
    await setup();
    render();
    openTab('Слоти');

    buttonWithText(host, 'Додати рампу').click();
    fixture.detectChanges();

    expect(host.textContent).not.toContain('Потрібна щонайменше одна рампа');
    expect(saveButton().disabled).toBe(false);
  });

  it('STC-31: зменшення тоннажу попереджає про вплив на наявні бронювання', async () => {
    await setup();
    render();
    openTab('Обмеження');
    expect(host.querySelector('.notice-warn')).toBeNull();

    const weight = required(
      host.querySelector<HTMLInputElement>('#max-weight'),
      'Поля тоннажу',
    );
    weight.value = String(Number(weight.value) - 0.5);
    weight.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(host.querySelector('.notice-warn')?.textContent).toContain(
      'Зменшення тоннажу може зачепити вже наявні бронювання',
    );
  });

  it('STC-10: усі сім днів у формі, навіть якщо неділі в конфігурації немає', async () => {
    await setup();
    const last = store.configurations.length - 1;
    store.configurations[last] = {
      ...store.configurations[last],
      receivingWindows: store.configurations[last].receivingWindows.filter(
        (w) => w.dayOfWeek !== 7,
      ),
    };

    render();
    openTab('Прийом поставок');

    const days = host.querySelectorAll('.day-row');
    expect(days).toHaveLength(7);

    buttonWithText(days[6], 'Додати інтервал').click();
    fixture.detectChanges();

    expect(days[6].querySelectorAll('.interval-row')).toHaveLength(1);
    expect(saveButton().disabled).toBe(false);
  });
});
