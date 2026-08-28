import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ArrivalsTableComponent } from './arrivals-table.component';
import { makeBooking } from '../../core/testing/booking.factory';
import { Booking, Ramp } from '../../core/models';

/**
 * Список прибуття: колонки будуються з реальних полів бронювання, а колонка
 * «ETA / Очікування» — з фактичних позначок часу.
 */
describe('ArrivalsTableComponent — список прибуття', () => {
  let fixture: ComponentFixture<ArrivalsTableComponent>;
  let host: HTMLElement;

  const ramps: Ramp[] = [
    { rampId: 'ramp-1', name: 'Рампа 1', active: true } as Ramp,
    { rampId: 'ramp-2', name: 'Рампа 2', active: true } as Ramp,
  ];

  /** «Зараз» фіксоване: інакше очікування рахувалося б від справжнього часу. */
  const NOW = '2026-08-29T09:40:00Z';

  function render(bookings: Booking[]): void {
    fixture = TestBed.createComponent(ArrivalsTableComponent);
    fixture.componentRef.setInput('bookings', bookings);
    fixture.componentRef.setInput('ramps', ramps);
    fixture.componentRef.setInput('nowIso', NOW);
    fixture.detectChanges();
    host = fixture.nativeElement as HTMLElement;
  }

  function rows(): HTMLElement[] {
    return Array.from(host.querySelectorAll('.listtable tbody tr'));
  }

  function text(): string {
    return (host.textContent ?? '').replace(/\s+/g, ' ');
  }

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [ArrivalsTableComponent] });
  });

  it('порожній список пояснює себе, а не показує голу таблицю', () => {
    render([]);
    expect(host.querySelector('.listtable')).toBeNull();
    expect(text()).toContain('немає бронювань');
  });

  it('рядок показує час слоту, постачальника, авто, рампу і статус', () => {
    render([
      makeBooking({
        supplierName: 'ТОВ «Молочний Дім»',
        orderId: 'ORD-2026-0247',
        rampId: 'ramp-2',
        vehicle: { plateNumber: 'AA1234BK', weightTons: 7, brand: 'DAF XF 106' },
      }),
    ]);

    const row = rows()[0].textContent ?? '';
    expect(row).toContain('ТОВ «Молочний Дім»');
    expect(row).toContain('ORD-2026-0247');
    expect(row).toContain('DAF XF 106');
    expect(row).toContain('AA1234BK');
    // рампа показується назвою, а не ідентифікатором
    expect(row).toContain('Рампа 2');
  });

  it('сортування за часом слоту перемикається', () => {
    render([
      makeBooking({ id: 'b1', slotStart: '2026-08-29T07:00:00Z', localTime: '10:00' }),
      makeBooking({ id: 'b2', slotStart: '2026-08-29T06:00:00Z', localTime: '09:00' }),
    ]);

    expect(rows()[0].textContent).toContain('09:00');

    const sortButton = host.querySelector('.listtable__sort') as HTMLButtonElement;
    sortButton.click();
    fixture.detectChanges();

    // після перемикання першим іде пізніший слот
    expect(rows()[0].textContent).toContain('10:00');
  });

  describe('колонка «ETA / Очікування»', () => {
    it('для завершеного показує час розвантаження', () => {
      render([
        makeBooking({
          status: 'completed',
          arrivedAt: '2026-08-29T05:30:00Z',
          unloadingStartedAt: '2026-08-29T05:40:00Z',
          completedAt: '2026-08-29T05:45:00Z',
        }),
      ]);
      expect(text()).toContain('Розвантажено о');
    });

    it('для того, хто в роботі, — час початку', () => {
      render([
        makeBooking({
          status: 'unloading',
          arrivedAt: '2026-08-29T06:00:00Z',
          unloadingStartedAt: '2026-08-29T06:20:00Z',
        }),
      ]);
      expect(text()).toContain('В роботі з');
    });

    it('для того, хто чекає, — скільки саме хвилин', () => {
      render([
        makeBooking({ status: 'arrived', arrivedAt: '2026-08-29T09:15:00Z' }),
      ]);
      // 09:15 → 09:40 = 25 хвилин очікування.
      expect(text()).toContain('25 хв');
      expect(text()).toContain('Прибув о');
    });

    it('для затриманого — наскільки пізніше і коли обіцяли', () => {
      render([
        makeBooking({
          status: 'booked',
          slotStart: '2026-08-29T08:00:00Z',
          delayed: { flag: true, reason: 'затори', eta: '2026-08-29T08:30:00Z' },
        }),
      ]);
      // запізнення рахується від початку слоту
      expect(text()).toContain('+30 хв');
      expect(text()).toContain('Очікується о');
    });
  });

  it('клік по рядку віддає бронювання назовні', () => {
    const opened: string[] = [];
    render([makeBooking({ id: 'b-42' })]);
    fixture.componentInstance.openBooking.subscribe((b: Booking) => opened.push(b.id));

    (host.querySelector('.listtable__more') as HTMLButtonElement).click();
    expect(opened).toEqual(['b-42']);
  });

  /** На телефоні таблиця не влазить — тому поруч живе картковий варіант. */
  it('той самий список продубльовано картками для вузького екрана', () => {
    render([makeBooking({ id: 'b1' }), makeBooking({ id: 'b2' })]);
    expect(host.querySelectorAll('.listcards__item')).toHaveLength(2);
  });
});
