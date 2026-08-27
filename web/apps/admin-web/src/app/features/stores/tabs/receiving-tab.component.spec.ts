import { ComponentFixture, TestBed } from '@angular/core/testing';
import { CalendarException, DayOfWeek, ReceivingWindow } from '../../../core/models';
import { addDays, kyivDate } from '../../../core/utils/time.util';
import {
  ReceivingChange,
  StoreReceivingTabComponent,
} from './receiving-tab.component';

/**
 * Бекенд віддає лише ті дні, для яких вікна задані: у пісочниці це пн–сб,
 * неділі в конфігурації немає взагалі.
 */
function windowsWithoutSunday(): ReceivingWindow[] {
  return ([1, 2, 3, 4, 5, 6] as DayOfWeek[]).map((dayOfWeek) => ({
    dayOfWeek,
    intervals: [{ from: '08:00', to: '18:00' }],
  }));
}

function buttonWithText(scope: Element, text: string): HTMLButtonElement {
  const button = Array.from(scope.querySelectorAll('button')).find((b) =>
    (b.textContent ?? '').includes(text),
  );
  if (!button) {
    throw new Error(`Кнопки «${text}» немає в розмітці`);
  }
  return button;
}

function fillInput(scope: Element, selector: string, value: string): void {
  const input = scope.querySelector<HTMLInputElement>(selector);
  if (!input) {
    throw new Error(`Поля ${selector} немає в розмітці`);
  }
  input.value = value;
  input.dispatchEvent(new Event('input'));
}

describe('Вкладка «Прийом поставок»', () => {
  let fixture: ComponentFixture<StoreReceivingTabComponent>;
  let host: HTMLElement;
  let changes: ReceivingChange[];

  function setup(
    windows: readonly ReceivingWindow[] = windowsWithoutSunday(),
    exceptions: readonly CalendarException[] = [],
  ): void {
    fixture = TestBed.createComponent(StoreReceivingTabComponent);
    fixture.componentRef.setInput('windows', windows);
    fixture.componentRef.setInput('exceptions', exceptions);
    fixture.componentRef.setInput('slotSizeMinutes', 30);
    fixture.componentRef.setInput('canEdit', true);
    changes = [];
    fixture.componentInstance.changed.subscribe((change) => changes.push(change));
    fixture.detectChanges();
    host = fixture.nativeElement as HTMLElement;
  }

  it('STC-10: показує всі сім днів, навіть якщо в конфігурації їх менше', () => {
    setup();
    expect(host.querySelectorAll('.day-row')).toHaveLength(7);
  });

  it('STC-10: інтервал додається для дня, якого немає в конфігурації', () => {
    setup();
    const sunday = host.querySelectorAll('.day-row')[6];
    expect(sunday.querySelectorAll('.interval-row')).toHaveLength(0);

    buttonWithText(sunday, 'Додати інтервал').click();
    fixture.detectChanges();

    const times = sunday.querySelectorAll<HTMLInputElement>('input[type=time]');
    expect(sunday.querySelectorAll('.interval-row')).toHaveLength(1);
    expect([times[0].value, times[1].value]).toEqual(['08:00', '18:00']);
    expect(
      changes.at(-1)?.receivingWindows.find((w) => w.dayOfWeek === 7)?.intervals,
    ).toEqual([{ from: '08:00', to: '18:00' }]);
  });

  it('STC-10: інтервали наявних днів не губляться', () => {
    setup();
    const monday = host.querySelectorAll('.day-row')[0];
    expect(monday.querySelectorAll('.interval-row')).toHaveLength(1);

    buttonWithText(monday, 'Додати інтервал').click();
    fixture.detectChanges();

    expect(monday.querySelectorAll('.interval-row')).toHaveLength(2);
    expect(
      changes.at(-1)?.receivingWindows.find((w) => w.dayOfWeek === 1)?.intervals,
    ).toHaveLength(2);
  });

  it('STC-12: другий виняток на ту саму дату відхиляється', () => {
    setup();
    const date = addDays(kyivDate(), 10);

    fillInput(host, '#exc-date', date);
    fillInput(host, '#exc-reason', 'UITEST перший');
    buttonWithText(host, 'Додати виняток').click();
    fixture.detectChanges();
    expect(host.querySelectorAll('table.data tbody tr')).toHaveLength(1);

    fillInput(host, '#exc-reason', 'UITEST другий');
    buttonWithText(host, 'Додати виняток').click();
    fixture.detectChanges();

    expect(host.querySelectorAll('table.data tbody tr')).toHaveLength(1);
    expect(host.textContent).toContain('Виняток на цю дату вже існує');
    expect(changes.at(-1)?.exceptions).toHaveLength(1);
  });

  it('STC-12: виняток на іншу дату додається поруч із наявним', () => {
    setup();
    const today = kyivDate();

    fillInput(host, '#exc-date', addDays(today, 10));
    fillInput(host, '#exc-reason', 'UITEST перший');
    buttonWithText(host, 'Додати виняток').click();
    fixture.detectChanges();

    fillInput(host, '#exc-date', addDays(today, 11));
    fillInput(host, '#exc-reason', 'UITEST другий');
    buttonWithText(host, 'Додати виняток').click();
    fixture.detectChanges();

    expect(host.querySelectorAll('table.data tbody tr')).toHaveLength(2);
    expect(host.textContent).not.toContain('Виняток на цю дату вже існує');
  });
});
