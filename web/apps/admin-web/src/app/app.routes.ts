import { Route } from '@angular/router';
import { authGuard, guestGuard, sectionGuard } from './core/auth/auth.guards';

export const appRoutes: Route[] = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/shell/shell.page').then((m) => m.ShellPage),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'stores' },
      {
        path: 'stores',
        canActivate: [sectionGuard('stores')],
        loadComponent: () =>
          import('./features/stores/store-list.page').then((m) => m.StoreListPage),
      },
      {
        path: 'stores/:id',
        canActivate: [sectionGuard('stores')],
        loadComponent: () =>
          import('./features/stores/store-detail.page').then(
            (m) => m.StoreDetailPage,
          ),
      },
      {
        path: 'suppliers',
        canActivate: [sectionGuard('suppliers')],
        loadComponent: () =>
          import('./features/suppliers/supplier-list.page').then(
            (m) => m.SupplierListPage,
          ),
      },
      {
        path: 'suppliers/:id',
        canActivate: [sectionGuard('suppliers')],
        loadComponent: () =>
          import('./features/suppliers/supplier-detail.page').then(
            (m) => m.SupplierDetailPage,
          ),
      },
      {
        path: 'mcp-sync',
        canActivate: [sectionGuard('sync')],
        loadComponent: () =>
          import('./features/sync/sync.page').then((m) => m.SyncPage),
      },
      {
        path: 'analytics',
        canActivate: [sectionGuard('analytics')],
        loadComponent: () =>
          import('./features/analytics/analytics.page').then((m) => m.AnalyticsPage),
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
