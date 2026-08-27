export {
  placeOnTimeline,
  timelineTicks,
  computeTimelineBounds,
} from '../../core/util/board.util';
export type {
  TimelineBounds,
  TimelinePlacement,
} from '../../core/util/board.util';

import { toHhMm } from '../../core/util/date.util';

export function toHhMmLabel(minutes: number): string {
  return toHhMm(minutes);
}
