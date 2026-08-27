import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

export interface Crumb {
  readonly label: string;
  readonly link?: readonly string[];
}

/** UI-03: breadcrumbs на всіх вкладених екранах. */
@Component({
  selector: 'app-breadcrumbs',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <nav class="breadcrumbs">
      @for (crumb of crumbs(); track $index) {
        @if (crumb.link && !$last) {
          <a [routerLink]="crumb.link">{{ crumb.label }}</a>
        } @else {
          <span [class.muted]="!$last">{{ crumb.label }}</span>
        }
        @if (!$last) {
          <span class="breadcrumbs-sep">→</span>
        }
      }
    </nav>
  `,
})
export class BreadcrumbsComponent {
  readonly crumbs = input.required<readonly Crumb[]>();
}
