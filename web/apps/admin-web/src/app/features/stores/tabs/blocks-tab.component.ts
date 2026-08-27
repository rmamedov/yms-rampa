import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
  signal,
} from '@angular/core';
import { Ramp, SlotBlock } from '../../../core/models';
import { SlotBlockDraft } from '../../../core/data/stores.api';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import {
  diffDays,
  formatDate,
  formatTime,
  isoToKyivDate,
  isValidDate,
  kyivDate,
  kyivDateTimeToIso,
  timeToMinutes,
} from '../../../core/utils/time.util';
import { validateReason } from '../../../core/utils/validators.util';

/**
 * Вкладка «Блокування слотів» (STC-50…STC-52).
 * Ресурс /stores/{id}/slot-blocks: межі періоду — UTC-мітки blockFrom/blockTo,
 * тому локальні дата+час перетворюються в ISO перед відправкою.
 */
@Component({
  selector: 'app-store-blocks-tab',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  templateUrl: './blocks-tab.component.html',
})
export class StoreBlocksTabComponent {
  readonly blocks = input.required<readonly SlotBlock[]>();
  readonly ramps = input.required<readonly Ramp[]>();
  readonly canEdit = input(false);

  readonly blockCreate = output<SlotBlockDraft>();
  readonly blockRelease = output<string>();

  protected readonly errors = signal<readonly string[]>([]);

  protected readonly date = signal(kyivDate());
  protected readonly from = signal('12:00');
  protected readonly to = signal('15:00');
  protected readonly rampIds = signal<readonly string[]>([]);
  protected readonly reason = signal('');

  protected readonly formatDate = formatDate;
  protected readonly formatTime = formatTime;
  protected readonly blockDate = (block: SlotBlock): string =>
    formatDate(isoToKyivDate(block.blockFrom));

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
        const ramp = this.ramps().find((r) => r.id === id);
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
    this.blockCreate.emit({
      rampIds: [...this.rampIds()],
      blockFrom: kyivDateTimeToIso(this.date(), this.from()),
      blockTo: kyivDateTimeToIso(this.date(), this.to()),
      reason: this.reason().trim(),
    });
    this.reason.set('');
    this.rampIds.set([]);
  }

  /** STC-52: зняття блокування повертає слоти в available (SlotReleased). */
  protected releaseBlock(id: string): void {
    this.blockRelease.emit(id);
  }
}
