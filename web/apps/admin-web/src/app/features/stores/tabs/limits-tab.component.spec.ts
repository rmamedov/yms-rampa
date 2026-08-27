import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StoreLimitsTabComponent } from './limits-tab.component';

describe('Вкладка «Обмеження»', () => {
  let fixture: ComponentFixture<StoreLimitsTabComponent>;
  let host: HTMLElement;

  /**
   * Батько (StoreDetailPage) на кожну зміну одразу оновлює чернетку,
   * і те саме значення повертається у вхідний сигнал вкладки.
   */
  function setup(maxVehicleWeightTons: number | null = 7.5): void {
    fixture = TestBed.createComponent(StoreLimitsTabComponent);
    fixture.componentRef.setInput('maxVehicleWeightTons', maxVehicleWeightTons);
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
    expect(warning()).toContain('Зменшення тоннажу може зачепити вже наявні бронювання');
  });

  it('STC-31: збільшення тоннажу попередження не показує', () => {
    setup(7.5);
    setWeight('8');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('STC-31: повернення до початкового значення прибирає попередження', () => {
    setup(7.5);
    setWeight('7');
    expect(host.querySelector('.notice-warn')).not.toBeNull();

    setWeight('7.5');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });

  it('STC-31: еталон оновлюється, коли значення приходить ззовні', () => {
    setup(7.5);
    setWeight('7');
    expect(host.querySelector('.notice-warn')).not.toBeNull();

    // Батько скасував зміни і повернув збережену конфігурацію.
    fixture.componentRef.setInput('maxVehicleWeightTons', 7.5);
    fixture.detectChanges();

    expect(host.querySelector('.notice-warn')).toBeNull();
    expect(host.querySelector<HTMLInputElement>('#max-weight')?.value).toBe('7.5');
  });

  it('порожнє поле тоннажу попередження не показує', () => {
    setup(7.5);
    setWeight('');
    expect(host.querySelector('.notice-warn')).toBeNull();
  });
});
