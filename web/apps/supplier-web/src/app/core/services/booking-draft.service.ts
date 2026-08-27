import { Injectable, signal } from '@angular/core';
import type { VehicleInput } from '../models/models';

export interface BookingDraft {
  readonly vehicleId: string | null;
  readonly newVehicle: VehicleInput | null;
  readonly orderId: string;
  readonly palletsCount: number | null;
}

export const EMPTY_DRAFT: BookingDraft = {
  vehicleId: null,
  newVehicle: null,
  orderId: '',
  palletsCount: null,
};

/**
 * SUP-ERR-01: після 409 введені дані форми зберігаються,
 * щоб повторно обрати інший слот без повторного заповнення.
 */
@Injectable({ providedIn: 'root' })
export class BookingDraftService {
  readonly draft = signal<BookingDraft>(EMPTY_DRAFT);

  save(draft: BookingDraft): void {
    this.draft.set(draft);
  }

  reset(): void {
    this.draft.set(EMPTY_DRAFT);
  }
}
