import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { firstValueFrom } from 'rxjs';
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

describe('Картка магазину — збереження конфігурації', () => {
  let fixture: ComponentFixture<StoreDetailPage>;
  let host: HTMLElement;
  let db: MockDb;
  let configured: MockStore;

  async function setup(): Promise<void> {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        MockDb,
        { provide: AuthApi, useClass: MockAuthApi },
        { provide: StoresApi, useClass: MockStoresApi },
        { provide: SuppliersApi, useClass: MockSuppliersApi },
        { provide: MOCK_LATENCY, useValue: 0 },
      ],
    });
    db = TestBed.inject(MockDb);
    db.reset();
    const found = db.state.stores.find((s) => s.configurations.length > 0);
    if (!found) {
      throw new Error('У пісочниці немає жодного налаштованого магазину');
    }
    configured = found;
    await firstValueFrom(
      TestBed.inject(AuthService).login('super.admin@silpo.ua', 'demo'),
    );
    TestBed.overrideProvider(ActivatedRoute, {
      useValue: {
        paramMap: of(convertToParamMap({ id: configured.card.id })),
      },
    });
  }

  function render(): void {
    fixture = TestBed.createComponent(StoreDetailPage);
    fixture.detectChanges();
    host = fixture.nativeElement as HTMLElement;
  }

  function openTab(label: string): void {
    const tabs = host.querySelector('.tabs');
    if (!tabs) {
      throw new Error('Вкладок немає в розмітці');
    }
    buttonWithText(tabs, label).click();
    fixture.detectChanges();
  }

  function saveButton(): HTMLButtonElement {
    const button = host.querySelector<HTMLButtonElement>('button.btn-primary');
    if (!button) {
      throw new Error('Кнопки «Зберегти» немає в розмітці');
    }
    return button;
  }

  afterEach(() => localStorage.clear());

  it('STC-21: без жодної рампи «Зберегти» заблоковано', async () => {
    await setup();
    render();
    openTab('Слоти');

    const ramps = host.querySelector('table.data');
    if (!ramps) {
      throw new Error('Таблиці рамп немає в розмітці');
    }
    let rows = ramps.querySelectorAll('tbody tr').length;
    expect(rows).toBeGreaterThan(0);
    while (rows > 0) {
      buttonWithText(ramps.querySelectorAll('tbody tr')[0], 'Видалити').click();
      fixture.detectChanges();
      rows = ramps.querySelectorAll('tbody tr[data-ramp], tbody tr').length;
      // Порожній стан таблиці — один рядок-заглушка без кнопки «Видалити».
      if (
        Array.from(ramps.querySelectorAll('tbody tr')).every(
          (tr) => !(tr.textContent ?? '').includes('Видалити'),
        )
      ) {
        break;
      }
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

    expect(saveButton().disabled).toBe(false);
  });

  it('STC-10: у вкладці «Прийом поставок» усі сім днів навіть без неділі в конфігурації', async () => {
    await setup();
    const config = configured.configurations[configured.configurations.length - 1];
    configured.configurations[configured.configurations.length - 1] = {
      ...config,
      receivingWindows: config.receivingWindows.filter((w) => w.dayOfWeek !== 7),
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
