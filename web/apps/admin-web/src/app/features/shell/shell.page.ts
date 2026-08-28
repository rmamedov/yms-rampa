import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../core/i18n/translate.pipe';
import { SECTION_ORDER, SectionId } from '../../core/rbac/permissions';

interface NavItem {
  readonly section: SectionId;
  readonly link: string;
  readonly labelKey: string;
}

const NAV: readonly NavItem[] = [
  { section: 'stores', link: '/stores', labelKey: 'nav.stores' },
  { section: 'suppliers', link: '/suppliers', labelKey: 'nav.suppliers' },
  { section: 'users', link: '/users', labelKey: 'nav.users' },
  { section: 'sync', link: '/mcp-sync', labelKey: 'nav.sync' },
  { section: 'analytics', link: '/analytics', labelKey: 'nav.analytics' },
  { section: 'audit', link: '/audit', labelKey: 'nav.audit' },
];

/** Каркас адмінки: бічна навігація за ролями (ADM-02) + outlet. */
@Component({
  selector: 'app-shell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, TranslatePipe],
  template: `
    <div class="shell">
      <aside class="sidebar">
        <div class="sidebar-brand">{{ 'app.brand' | t }}</div>
        <nav>
          @for (item of visibleNav(); track item.section) {
            <a
              class="sidebar-link"
              [routerLink]="item.link"
              routerLinkActive="active"
              >{{ item.labelKey | t }}</a
            >
          }
        </nav>
        <div class="sidebar-footer">
          <div class="sidebar-user">{{ auth.user()?.fullName }}</div>
          <div class="sidebar-role">{{ roleLabel() | t }}</div>
          <button type="button" class="btn btn-sm" (click)="logout()">
            {{ 'nav.logout' | t }}
          </button>
        </div>
      </aside>
      <main class="content">
        <router-outlet />
      </main>
    </div>
  `,
})
export class ShellPage {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly visibleNav = computed(() =>
    NAV.filter(
      (item) =>
        SECTION_ORDER.includes(item.section) && this.auth.canSee(item.section),
    ),
  );

  protected readonly roleLabel = computed(() => `role.${this.auth.role() ?? 'analyst'}`);

  protected logout(): void {
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
