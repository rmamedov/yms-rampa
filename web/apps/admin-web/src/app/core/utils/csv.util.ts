/** Побудова CSV для експорту аналітики та аудиту (ANL-11, AUD-02). */

export interface CsvColumn<T> {
  readonly header: string;
  readonly value: (row: T) => string | number | null | undefined;
}

const SEPARATOR = ',';
/** BOM, щоб Excel коректно відкривав UTF-8. */
export const UTF8_BOM = '﻿';

export function escapeCsvCell(value: unknown): string {
  if (value === null || value === undefined) {
    return '';
  }
  const text = String(value);
  if (/[",\n\r]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }
  return text;
}

export function csvRow(cells: readonly unknown[]): string {
  return cells.map(escapeCsvCell).join(SEPARATOR);
}

/**
 * ANL-11: у експорт потрапляють застосовані фільтри окремим рядком-заголовком
 * і всі рядки вибірки без обмеження пагінацією.
 */
export function buildCsv<T>(
  rows: readonly T[],
  columns: readonly CsvColumn<T>[],
  filterHeader?: string,
): string {
  const lines: string[] = [];
  if (filterHeader) {
    lines.push(csvRow([filterHeader]));
  }
  lines.push(csvRow(columns.map((c) => c.header)));
  for (const row of rows) {
    lines.push(csvRow(columns.map((c) => c.value(row))));
  }
  return UTF8_BOM + lines.join('\r\n');
}

export function csvFileName(prefix: string, at: Date = new Date()): string {
  const stamp = at.toISOString().slice(0, 19).replace(/[:T]/g, '-');
  return `${prefix}-${stamp}.csv`;
}
