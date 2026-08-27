import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { AuditDialogComponent } from './audit-dialog.component';
import { AuthService } from '../../../core/auth/auth.service';
import { AuthGateway, StoreGateway } from '../../../core/data/gateways';
import { TokenStorageService } from '../../../core/auth/token-storage.service';
import { toLoginResponse } from '../../../core/api/wire.mapper';
import { WireStaffUser } from '../../../core/api/wire.model';
import { StatusChange } from '../../../core/models/booking.model';
import { makeBooking } from '../../../core/testing/booking.factory';

/**
 * Колонка «Хто» журналу дій (STW-33): раніше в ній стояв UUID облікового
 * запису. Тепер підпис — людиночитаний, а ідентифікатор лишається технічним
 * рядком нижче.
 */

const ME: WireStaffUser = {
  id: 'u-me',
  email: 'manager@silpo.ua',
  fullName: 'Дмитро Савченко',
  role: 'store_manager',
  roleLabel: 'Керівник магазину',
  scope: { storeIds: ['s-1'], networkWide: false },
  twoFactorEnabled: false,
  permissions: [],
};

function entry(overrides: Partial<StatusChange> = {}): StatusChange {
  return {
    from: 'booked',
    to: 'arrived',
    at: '2026-08-27T06:50:00Z',
    by: 'u-other',
    byRole: 'store_operator',
    byContour: 'staff',
    byLabel: 'Приймальник магазину',
    meta: {},
    ...overrides,
  };
}

describe('AuditDialogComponent — колонка «Хто»', () => {
  let fixture: ComponentFixture<AuditDialogComponent>;

  function render(history: readonly StatusChange[]): string {
    fixture.componentRef.setInput(
      'booking',
      makeBooking({ statusHistory: history }),
    );
    fixture.detectChanges();
    return (fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [AuditDialogComponent],
      providers: [
        TokenStorageService,
        {
          provide: AuthGateway,
          useValue: {
            login: () => of(toLoginResponse(tokenResponse())),
            refresh: () => of(toLoginResponse(tokenResponse())),
            logout: () => of(undefined),
          },
        },
        { provide: StoreGateway, useValue: { getStores: () => of([]) } },
      ],
    }).compileComponents();

    TestBed.inject(AuthService)
      .login({ email: ME.email, password: 'x' })
      .subscribe();
    fixture = TestBed.createComponent(AuditDialogComponent);
  });

  function tokenResponse() {
    return {
      tokenType: 'Bearer',
      accessToken: 'a1',
      expiresIn: 900,
      accessExpiresAt: new Date(Date.now() + 900_000).toISOString(),
      refreshToken: 'r1',
      refreshExpiresAt: new Date(Date.now() + 86_400_000).toISOString(),
      sessionId: 'sess-1',
      user: ME,
    };
  }

  it('показує позначку ролі виконавця, а не ідентифікатор', () => {
    const text = render([entry()]);
    expect(text).toContain('Приймальник магазину');
    expect(text).toContain('Заплановано');
    expect(text).toContain('Очікує на території');
  });

  it('для власних дій називає користувача на імʼя і додає його роль', () => {
    const text = render([
      entry({ by: 'u-me', byRole: 'store_manager', byLabel: 'Керівник магазину' }),
    ]);
    expect(text).toContain('Дмитро Савченко');
    expect(text).toContain('Керівник магазину');
  });

  it('дію планового завдання називає завданням системи', () => {
    const text = render([
      entry({
        from: 'booked',
        to: 'no_show',
        by: 'system',
        byRole: null,
        byContour: 'system',
        byLabel: 'Планове завдання системи',
        meta: { auto: true },
      }),
    ]);
    expect(text).toContain('Планове завдання системи');
    expect(text).toContain('Не приїхав');
  });

  it('запис без збереженої ролі — чесне «невідомо», а не UUID у підписі', () => {
    const text = render([
      entry({ by: 'u-other', byRole: null, byContour: null, byLabel: null }),
    ]);
    const actor =
      (fixture.nativeElement as HTMLElement).querySelector('.log__actor')
        ?.textContent ?? '';
    expect(actor.trim()).toBe('Невідомо');
    // Ідентифікатор лишається доступним, але окремим технічним рядком.
    expect(text).toContain('ID u-other');
  });

  it('впорядковує записи за часом і показує кожен перехід', () => {
    const text = render([
      entry({ from: 'arrived', to: 'unloading', at: '2026-08-27T07:10:00Z' }),
      entry({ from: null, to: 'booked', at: '2026-08-26T05:00:00Z' }),
    ]);
    expect(text.indexOf('Заплановано')).toBeLessThan(
      text.indexOf('Розвантаження'),
    );
  });

  it('показує метадані переходу поруч зі зміною статусу', () => {
    const text = render([
      entry({
        from: 'unloading',
        to: 'completed',
        meta: { unloadedPalletsCount: 12 },
      }),
    ]);
    expect(text).toContain('unloadedPalletsCount: 12');
  });
});
