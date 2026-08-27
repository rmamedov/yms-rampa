import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StoreLimitsTabComponent } from './limits-tab.component';

describe('Вкладка «Обмеження»', () => {
  let fixture: ComponentFixture<StoreLimitsTabComponent>;
  let host: HTMLElement;

  /**
   * Батько (StoreDetailPage) на кожну зміну одразу оновлює чернетку,
   * і те саме значення повертається у вхідний сигнал вкладки; збережена
   * конфігурація при цьому лишається такою, як була.
   */
  function setup(saved: number | null = 7.5): void {
    fixture = TestBed.createComponent(StoreLimitsTabComponent);
    fixture.componentRef.setInput('maxVehicleWeightTons', saved);
    fixture.componentRef.setInput('savedMaxVehicleWeightTons', saved);
    fixture.componentRef.setInput('leadTimeMinutes', 60);
    fixture.componentRef.setInput('bookingHorizonDays', 14);
    fixture.componentRef.setInput('noShowGraceMinutes', 30);
    fixture.componentRef.setInput('holdMaxMinutes', 15);
    fixture.componentRef.setInput('canEdit', true);
    fixture.componentInstance.changed.subscribe((change) =>
      fixture.componentRef.setInput(
        'maxVehicleWeightTons',
        change.maxVehicleWeightTons,
      ),
    );
    fixture.detectChanges();
    host = fixture.nativeElement as HTMLElement;
  }

  function setWeight(value: string): void {
    const input = host.querySelector<HTMLInputElement>('#max-weight');
    if (!input) {
      throw new Error('Поля «Максимальний тоннаж авто» немає в розмітці');
    }
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function warning(): string {
    return host.querySelector('.notice-warn')?.textContent?.trim() ?? '';
  }

  it('без змін попередження немає', () => {
    setup();
    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('STC-31: зменшення тоннажу попереджає про вплив на наявні бронювання', () => {
    setup(7.5);
    setWeight('7');
    expect(warning()).toContain(
      'Зменшення тоннажу може зачепити вже наявні бронювання',
    );
  });

  it('STC-31: збільшення тоннажу попередження не показує', () => {
    setup(7.5);
    setWeight('8');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('STC-31: повернення до збереженого значення прибирає попередження', () => {
    setup(7.5);
    setWeight('7');
    expect(host.querySelector('.notice-warn')).not.toBeNull();

    setWeight('7.5');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('STC-31: після збереження нової версії попередження зникає', () => {
    setup(7.5);
    setWeight('7');
    expect(host.querySelector('.notice-warn')).not.toBeNull();

    // Магазин перезавантажено: 7.0 тепер і є чинною конфігурацією.
    fixture.componentRef.setInput('savedMaxVehicleWeightTons', 7);
    fixture.detectChanges();

    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('для ненастроєного магазину попередження не показується', () => {
    setup(null);
    setWeight('7');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('порожнє поле тоннажу попередження не показує', () => {
    setup(7.5);
    setWeight('');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });
});
