import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

/** Пускає далі лише автентифікованих; без ролі магазину — «Доступ заборонено». */
export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!auth.isAuthenticated()) {
    return router.createUrlTree(['/login']);
  }
  if (!auth.hasStoreAccess()) {
    return router.createUrlTree(['/no-access']);
  }
  return true;
};

/** Не пускає вже автентифікованого користувача назад на логін. */
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  if (auth.isAuthenticated() && auth.hasStoreAccess()) {
    return router.createUrlTree(['/today']);
  }
  return true;
};
