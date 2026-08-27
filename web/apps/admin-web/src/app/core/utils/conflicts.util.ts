import {
  Booking,
  ConfigConflict,
  ConflictDecision,
  ConflictReason,
  DayOfWeek,
  SlotBlock,
  StoreConfig,
  YmsStatus,
} from '../models';
import { diffDays, timeToMinutes } from './time.util';
import { effectiveIntervals, generateSlotStarts } from './store-config.util';

export interface ConflictCheckInput {
  readonly bookings: readonly Booking[];
  readonly nextConfig: StoreConfig;
  /** YYYY-MM-DD — дата набрання чинності (STC-60) */
  readonly effectiveFrom: string;
  readonly nextYmsStatus?: YmsStatus;
  readonly rampLabels?: Readonly<Record<string, string>>;
}

const ACTIVE_STATUSES = new Set(['booked', 'arrived']);

/**
 * STC-62: знаходить бронювання, які після дати X не мають відповідного слота
 * у новій сітці або порушують нові обмеження.
 * Існуючі бронювання ніколи не змінюються автоматично (STC-61) — лише позначаються.
 */
export function detectConflicts(input: ConflictCheckInput): ConfigConflict[] {
  const { bookings, nextConfig, effectiveFrom } = input;
  const conflicts: ConfigConflict[] = [];

  for (const booking of bookings) {
    if (!ACTIVE_STATUSES.has(booking.status)) {
      continue;
    }
    // до дати X діє попередня версія конфігурації
    if (diffDays(effectiveFrom, booking.date) < 0) {
      continue;
    }
    const reason = conflictReasonFor(booking, nextConfig, input.nextYmsStatus);
    if (reason !== null) {
      conflicts.push({
        id: `cf-${booking.id}`,
        booking,
        reason,
        rampLabel:
          input.rampLabels?.[booking.rampId] ??
          nextConfig.ramps.find((r) => r.id === booking.rampId)?.number.toString() ??
          '—',
      });
    }
  }
  return conflicts;
}

function conflictReasonFor(
  booking: Booking,
  config: StoreConfig,
  nextStatus?: YmsStatus,
): ConflictReason | null {
  if (nextStatus === 'paused' || nextStatus === 'archived') {
    return 'store_paused';
  }
  const ramp = config.ramps.find((r) => r.id === booking.rampId);
  if (!ramp || !ramp.enabled) {
    return 'ramp_disabled';
  }
  if (
    config.maxVehicleWeightTons !== null &&
    booking.vehicleWeightTons > config.maxVehicleWeightTons
  ) {
    return 'weight_limit';
  }
  if (isInsideBlock(booking, config.slotBlocks)) {
    return 'blocked_range';
  }
  if (isReservedForOther(booking, config)) {
    return 'reserved_for_other';
  }
  const intervals = effectiveIntervals(config, booking.date);
  if (intervals.length === 0) {
    return 'no_window';
  }
  if (config.slotSizeMinutes === null) {
    return 'no_window';
  }
  const starts = generateSlotStarts(intervals, config.slotSizeMinutes);
  if (!starts.includes(booking.startTime)) {
    return 'slot_grid_shift';
  }
  return null;
}

/** STC-51: бронювання в діапазоні блокування — конфлікт, а не автоскасування. */
export function isInsideBlock(
  booking: Booking,
  blocks: readonly SlotBlock[],
): boolean {
  const start = timeToMinutes(booking.startTime);
  return blocks.some((block) => {
    if (!block.active || block.date !== booking.date) {
      return false;
    }
    const coversRamp =
      block.rampIds.length === 0 || block.rampIds.includes(booking.rampId);
    if (!coversRamp) {
      return false;
    }
    return start >= timeToMinutes(block.from) && start < timeToMinutes(block.to);
  });
}

/** STC-43: слот заброньовано іншим постачальником, а на нього створюють резерв. */
export function isReservedForOther(booking: Booking, config: StoreConfig): boolean {
  const bookingDay = isoDayOfWeek(booking.date);
  return config.reservedRules.some((rule) => {
    if (!rule.active || rule.rampId !== booking.rampId) {
      return false;
    }
    if (rule.slotStartTime !== booking.startTime) {
      return false;
    }
    if (rule.supplierId === booking.supplierId) {
      return false;
    }
    if (diffDays(rule.validFrom, booking.date) < 0) {
      return false;
    }
    if (rule.validTo && diffDays(rule.validTo, booking.date) > 0) {
      return false;
    }
    if (rule.date !== null && rule.date !== '') {
      return rule.date === booking.date;
    }
    return rule.dayOfWeek === bookingDay;
  });
}

function isoDayOfWeek(date: string): DayOfWeek {
  const jsDay = new Date(`${date}T00:00:00Z`).getUTCDay();
  return (jsDay === 0 ? 7 : jsDay) as DayOfWeek;
}

/** STC-64: збереження неможливе, доки по кожному конфлікту не обрано рішення. */
export function unresolvedCount(
  conflicts: readonly ConfigConflict[],
  decisions: readonly ConflictDecision[],
): number {
  const resolved = new Set(decisions.map((d) => d.conflictId));
  return conflicts.filter((c) => !resolved.has(c.id)).length;
}

export function canSaveConfig(
  conflicts: readonly ConfigConflict[],
  decisions: readonly ConflictDecision[],
): boolean {
  return unresolvedCount(conflicts, decisions) === 0;
}
