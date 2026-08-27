import { Injectable, signal } from '@angular/core';
import type { Booking } from '../models/models';

/**
 * SUP-RS-03: перенесення бронювання відкриває стандартний флоу вибору слота
 * з предзаповненими авто / orderId / палетами.
 */
@Injectable({ providedIn: 'root' })
export class TransferService {
  readonly source = signal<Booking | null>(null);

  start(booking: Booking): void {
    this.source.set(booking);
  }

  clear(): void {
    this.source.set(null);
  }
}
