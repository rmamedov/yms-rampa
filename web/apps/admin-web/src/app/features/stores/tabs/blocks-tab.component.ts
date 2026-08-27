import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core';
import { Ramp, SlotBlock, StoreConfig } from '../../../core/models';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  diffDays,
  formatDate,
  isValidDate,
  kyivDate,
  timeToMinutes,
} from '../../../core/utils/time.util';
import { validateReason } from '../../../core/utils/validators.util';

/** Вкладка «Блокування слотів»: разові блокування з причиною (STC-50…STC-52). */
@Component({
  selector: 'app-store-blocks-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './blocks-tab.component.html',
})
export class StoreBlocksTabComponent {
  readonly config = input.required<StoreConfig>();
  readonly canEdit = input(false);
  readonly changed = output<readonly SlotBlock[]>();

  protected readonly blocks = signal<SlotBlock[]>([]);
  protected readonly errors = signal<readonly string[]>([]);

  protected readonly date = signal(kyivDate());
  protected readonly from = signal('12:00');
  protected readonly to = signal('15:00');
  protected readonly rampIds = signal<readonly string[]>([]);
  protected readonly reason = signal('');

  protected readonly formatDate = formatDate;
  protected readonly ramps = computed<readonly Ramp[]>(() => this.config().ramps);

  constructor() {
    effect(() => {
      this.blocks.set(this.config().slotBlocks.map((b) => ({ ...b })));
    });
  }

  protected toggleRamp(id: string): void {
    this.rampIds.update((ids) =>
      ids.includes(id) ? ids.filter((v) => v !== id) : [...ids, id],
    );
  }

  protected rampsLabel(block: SlotBlock): string {
    if (block.rampIds.length === 0) {
      return '';
    }
    return block.rampIds
      .map((id) => {
        const ramp = this.config().ramps.find((r) => r.id === id);
        return ramp ? (ramp.name ?? `№${ramp.number}`) : id;
      })
      .join(', ');
  }

  protected addBlock(): void {
    const errors: string[] = [];
    if (!isValidDate(this.date()) || diffDays(kyivDate(), this.date()) < 0) {
      errors.push('blocks.error.date');
    }
    if (timeToMinutes(this.from()) >= timeToMinutes(this.to())) {
      errors.push('receiving.error.order');
    }
    if (validateReason(this.reason(), 'blocks.error.reason') !== null) {
      errors.push('blocks.error.reason');
    }
    this.errors.set(errors);
    if (errors.length > 0) {
      return;
    }
    const block: SlotBlock = {
      id: `blk-${Date.now()}`,
      date: this.date(),
      from: this.from(),
      to: this.to(),
      rampIds: [...this.rampIds()],
      reason: this.reason().trim(),
      active: true,
      createdAt: new Date().toISOString(),
    };
    this.blocks.update((list) => [block, ...list]);
    this.reason.set('');
    this.rampIds.set([]);
    this.changed.emit(this.blocks());
  }

  /** STC-52: зняття блокування повертає слоти в available (SlotReleased). */
  protected release(id: string): void {
    this.blocks.update((list) =>
      list.map((b) => (b.id === id ? { ...b, active: false } : b)),
    );
    this.changed.emit(this.blocks());
  }
}
