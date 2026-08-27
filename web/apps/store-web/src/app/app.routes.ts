import { Route } from '@angular/router';
import { authGuard, guestGuard } from './core/auth/auth.guard';

export const appRoutes: Route[] = [
  { path: '', pathMatch: 'full', redirectTo: 'today' },
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'no-access',
    loadComponent: () =>
      import('./features/login/no-access.page').then((m) => m.NoAccessPage),
  },
  {
    path: 'today',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/today/today.page').then((m) => m.TodayPage),
  },
  {
    path: 'week',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/week/week.page').then((m) => m.WeekPage),
  },
  { path: '**', redirectTo: 'today' },
];
