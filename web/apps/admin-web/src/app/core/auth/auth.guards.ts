import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';
import { SectionId } from '../rbac/permissions';
import { ToastService } from '../ui/toast.service';

export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  if (auth.isAuthenticated()) {
    return true;
  }
  return router.createUrlTree(['/login'], {
    queryParams: { redirect: state.url },
  });
};

export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  return auth.isAuthenticated() ? router.createUrlTree(['/']) : true;
};

/** ADM-02: видимість і доступність розділів визначаються роллю. */
export function sectionGuard(section: SectionId): CanActivateFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);
    const toast = inject(ToastService);
    if (auth.canSee(section)) {
      return true;
    }
    toast.errorKey('error.RBAC_PERMISSION_DENIED');
    return router.createUrlTree([auth.isAuthenticated() ? '/' : '/login']);
  };
}
