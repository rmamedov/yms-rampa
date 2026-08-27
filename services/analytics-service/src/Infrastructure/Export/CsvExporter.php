<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

/**
 * ANL-11: експорт поточної вибірки у CSV. Кодування UTF-8, роздільник — кома,
 * застосовані фільтри виводяться окремим рядком-заголовком перед таблицею,
 * рядки не обмежуються пагінацією.
 */
final readonly class CsvExporter
{
    public const DELIMITER = ',';

    /**
     * @param list<string>       $headers
     * @param iterable<list<mixed>> $rows
     */
    public function export(string $filtersLine, array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Не вдалося сформувати CSV-експорт.');
        }

        $this->writeRow($handle, ['Фільтри: ' . $filtersLine]);
        $this->writeRow($handle, $headers);

        foreach ($rows as $row) {
            $this->writeRow($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param resource      $handle
     * @param list<mixed>   $row
     */
    private function writeRow($handle, array $row): void
    {
        $normalized = array_map(
            static fn (mixed $value): string => match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'так' : 'ні',
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => (string) $value,
            },
            $row,
        );

        fputcsv($handle, $normalized, self::DELIMITER, '"', '');
    }
}
