import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from './core/auth/auth.service';
import { BoardStore } from './core/data/board.store';
import { TranslatePipe } from './core/i18n/translate.pipe';
import { StoreScope } from './core/models/auth.model';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './app.html',
})
export class App {
  private readonly auth = inject(AuthService);
  private readonly store = inject(BoardStore);
  private readonly router = inject(Router);

  readonly profile = this.auth.profile;
  readonly stores = this.auth.stores;
  readonly selectedStore = this.auth.selectedStore;
  readonly showSwitcher = this.auth.showStoreSwitcher;
  readonly showChrome = computed(
    () => this.auth.isAuthenticated() && this.auth.hasStoreAccess(),
  );
  readonly toast = this.store.toast;

  readonly roleKey = computed(() => {
    const role = this.profile()?.role;
    return role ? `header.role.${role}` : '';
  });

  /**
   * Підпис магазину. Бекенд у профілі віддає лише storeIds, тому описові
   * частини зʼявляються тільки після завантаження конфігурації магазину.
   */
  storeLabel(store: StoreScope): string {
    const parts = [store.displayName];
    if (store.externalId) parts.push(store.externalId);
    if (store.city) {
      parts.push(store.address ? `${store.city}, ${store.address}` : store.city);
    }
    return parts.join(' · ');
  }

  /** STW-04: перемикання магазину повністю перезавантажує контекст. */
  onStoreChange(storeId: string): void {
    this.auth.selectStore(storeId);
    this.store.clearFilters();
    this.store.load();
  }

  logout(): void {
    this.store.stopPolling();
    this.auth.logout();
    void this.router.navigateByUrl('/login');
  }

  dismissToast(): void {
    this.store.dismissToast();
  }
}
