import { Pipe, PipeTransform } from '@angular/core';
import {
  kyivDateIso,
  kyivDayLabel,
  kyivFullDateLabel,
  kyivTimeHm,
  kyivWeekdayLabel,
} from '../../core/util/kyiv-time';

/** UTC ISO → «HH:mm» у Europe/Kyiv. */
@Pipe({ name: 'kyivTime' })
export class KyivTimePipe implements PipeTransform {
  transform(isoInstant: string): string {
    return kyivTimeHm(new Date(isoInstant));
  }
}

/** UTC ISO → «12 березня» у Europe/Kyiv. */
@Pipe({ name: 'kyivDate' })
export class KyivDatePipe implements PipeTransform {
  transform(isoInstant: string): string {
    return kyivDayLabel(kyivDateIso(new Date(isoInstant)));
  }
}

/** «YYYY-MM-DD» → «12 березня 2026, пн». */
@Pipe({ name: 'kyivDay' })
export class KyivDayPipe implements PipeTransform {
  transform(dateIso: string, withYear = true): string {
    const base = withYear
      ? kyivFullDateLabel(dateIso)
      : kyivDayLabel(dateIso);
    return `${base}, ${kyivWeekdayLabel(dateIso)}`;
  }
}
