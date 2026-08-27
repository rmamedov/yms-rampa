import { addDays } from './kyiv-time';

export interface DateStrip {
  readonly dates: readonly string[];
  readonly canPrev: boolean;
  readonly canNext: boolean;
}

/**
 * SUP-SLOT-01 / GRID-03: стрічка дат — за замовчуванням 7 днів уперед від
 * сьогодні, навігація «далі/назад» лише в межах горизонту бронювання філії.
 */
export function buildDateStrip(
  todayIso: string,
  startOffset: number,
  visibleDays: number,
  horizonDays: number,
): DateStrip {
  const maxOffset = Math.max(0, horizonDays - visibleDays + 1);
  const offset = Math.min(Math.max(0, startOffset), maxOffset);
  const dates: string[] = [];
  for (let i = 0; i < visibleDays; i++) {
    const dayOffset = offset + i;
    if (dayOffset > horizonDays) {
      break;
    }
    dates.push(addDays(todayIso, dayOffset));
  }
  return {
    dates,
    canPrev: offset > 0,
    canNext: offset + visibleDays <= horizonDays,
  };
}

/** Обмежує зсув стрічки допустимим діапазоном. */
export function clampOffset(
  offset: number,
  visibleDays: number,
  horizonDays: number,
): number {
  const maxOffset = Math.max(0, horizonDays - visibleDays + 1);
  return Math.min(Math.max(0, offset), maxOffset);
}
