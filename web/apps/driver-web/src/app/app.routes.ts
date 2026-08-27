import { Route } from '@angular/router';
import { driverGuard, guestGuard } from './core/auth/auth.guard';

export const appRoutes: Route[] = [
  { path: '', pathMatch: 'full', redirectTo: 'route' },
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'route',
    canActivate: [driverGuard],
    loadComponent: () =>
      import('./features/route-sheet/route-sheet.page').then(
        (m) => m.RouteSheetPage,
      ),
  },
  {
    // Друкована версія відкривається в новій вкладці (DRV-40).
    path: 'print/:date',
    canActivate: [driverGuard],
    loadComponent: () =>
      import('./features/print/print.page').then((m) => m.PrintPage),
  },
  { path: '**', redirectTo: 'route' },
];
