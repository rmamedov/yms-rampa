import { HttpContextToken } from '@angular/common/http';

/** Запит не потребує Authorization і не бере участі в refresh-циклі. */
export const SKIP_AUTH = new HttpContextToken<boolean>(() => false);
