import { Route } from '@angular/router';
import { authGuard, guestGuard } from './core/auth/auth.guard';

export const appRoutes: Route[] = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'route-sheets/:date/print',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/route-sheets/route-sheet-print.component').then(
        (m) => m.RouteSheetPrintComponent,
      ),
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./layout/shell.component').then((m) => m.ShellComponent),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'home' },
      {
        path: 'home',
        loadComponent: () =>
          import('./features/home/home.component').then((m) => m.HomeComponent),
      },
      {
        path: 'booking/cities',
        loadComponent: () =>
          import('./features/booking/city-list.component').then(
            (m) => m.CityListComponent,
          ),
      },
      {
        path: 'booking/cities/:city',
        loadComponent: () =>
          import('./features/booking/branch-list.component').then(
            (m) => m.BranchListComponent,
          ),
      },
      {
        path: 'booking/stores/:storeId',
        loadComponent: () =>
          import('./features/booking/branch-slots.component').then(
            (m) => m.BranchSlotsComponent,
          ),
      },
      {
        path: 'vehicles',
        loadComponent: () =>
          import('./features/vehicles/vehicles.component').then(
            (m) => m.VehiclesComponent,
          ),
      },
      {
        path: 'route-sheets',
        loadComponent: () =>
          import('./features/route-sheets/route-sheets.component').then(
            (m) => m.RouteSheetsComponent,
          ),
      },
      {
        path: 'route-sheets/:date',
        loadComponent: () =>
          import('./features/route-sheets/route-sheet-detail.component').then(
            (m) => m.RouteSheetDetailComponent,
          ),
      },
      {
        path: 'drivers',
        loadComponent: () =>
          import('./features/drivers/drivers.component').then(
            (m) => m.DriversComponent,
          ),
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
