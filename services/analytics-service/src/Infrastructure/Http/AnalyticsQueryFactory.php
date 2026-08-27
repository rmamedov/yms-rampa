<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Access\Actor;
use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\PeriodBucket;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingType;
use App\Domain\Clock\Clock;
use App\Domain\Exception\InvalidFilterException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір фільтрів дашборда з HTTP-запиту (ANL-10): період (з/по або пресети
 * «сьогодні», «7 днів», «30 днів»), місто, магазин (мультивибір),
 * постачальник (мультивибір), а також рампа, тип і статус бронювання.
 *
 * Дати в параметрах — локальні дати магазину (Europe/Kyiv); межі періоду
 * перетворюються в UTC-напівінтервал [from; to), де to — початок доби,
 * наступної за «по». Тому «з=по=2026-03-14» означає рівно одну добу.
 *
 * Фільтр магазинів — НЕ лише зручність користувача: він же несе скоуп доступу.
 * Тому запитані магазини завжди звужуються скоупом актора
 * (Actor::narrowStoreScope), а не приймаються на віру з query-рядка.
 */
final readonly class AnalyticsQueryFactory
{
    /** Максимальна довжина періоду — захист від вивантаження всієї історії. */
    public const MAX_PERIOD_DAYS = 366;

    public function __construct(private Clock $clock)
    {
    }

    /**
     * @throws \App\Domain\Access\AccessDeniedException якщо запитані магазини поза скоупом актора
     */
    public function fromRequest(Request $request, Actor $actor): AnalyticsQuery
    {
        [$from, $to] = $this->resolvePeriod($request);

        return new AnalyticsQuery(
            from: $from,
            to: $to,
            cities: $this->list($request, 'city'),
            storeIds: $actor->narrowStoreScope($this->list($request, 'storeId')),
            supplierIds: $this->list($request, 'supplierId'),
            rampIds: $this->list($request, 'rampId'),
            types: array_map($this->toType(...), $this->list($request, 'type')),
            statuses: array_map($this->toStatus(...), $this->list($request, 'status')),
        );
    }

    public function dimensionFromRequest(Request $request, Dimension $default = Dimension::Store): Dimension
    {
        $raw = $request->query->get('dimension');
        if ($raw === null || $raw === '') {
            return $default;
        }

        $dimension = Dimension::tryFrom((string) $raw);
        if ($dimension === null) {
            throw InvalidFilterException::invalidDimension(sprintf(
                'Невідомий розріз «%s». Доступні: %s.',
                (string) $raw,
                implode(', ', Dimension::codes()),
            ));
        }

        return $dimension;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolvePeriod(Request $request): array
    {
        $preset = $request->query->get('preset');
        $tz = PeriodBucket::storeTimeZone();
        $todayLocal = $this->clock->now()->setTimezone($tz)->setTime(0, 0);

        if (is_string($preset) && $preset !== '') {
            $from = match ($preset) {
                'today' => $todayLocal,
                '7d' => $todayLocal->modify('-6 days'),
                '30d' => $todayLocal->modify('-29 days'),
                default => throw InvalidFilterException::invalidPeriod(sprintf(
                    'Невідомий пресет періоду «%s». Доступні: today, 7d, 30d.',
                    $preset,
                )),
            };

            return [
                $from->setTimezone(new \DateTimeZone('UTC')),
                $todayLocal->modify('+1 day')->setTimezone(new \DateTimeZone('UTC')),
            ];
        }

        $fromRaw = $request->query->get('from');
        $toRaw = $request->query->get('to');

        if (!is_string($fromRaw) || $fromRaw === '' || !is_string($toRaw) || $toRaw === '') {
            throw InvalidFilterException::invalidPeriod(
                'Не вказано період: потрібні параметри from і to (або preset).',
            );
        }

        $from = $this->parseLocalDate($fromRaw, 'from')->setTime(0, 0);
        $to = $this->parseLocalDate($toRaw, 'to')->setTime(0, 0)->modify('+1 day');

        if ($from >= $to) {
            throw InvalidFilterException::invalidPeriod('Початок періоду не може бути пізнішим за кінець.');
        }

        $days = (int) $from->diff($to)->days;
        if ($days > self::MAX_PERIOD_DAYS) {
            throw InvalidFilterException::periodTooLong(sprintf(
                'Період задовгий: %d діб, максимум %d.',
                $days,
                self::MAX_PERIOD_DAYS,
            ));
        }

        return [
            $from->setTimezone(new \DateTimeZone('UTC')),
            $to->setTimezone(new \DateTimeZone('UTC')),
        ];
    }

    private function parseLocalDate(string $value, string $field): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, PeriodBucket::storeTimeZone());
        } catch (\Exception) {
            throw InvalidFilterException::invalidPeriod(sprintf(
                'Параметр «%s» містить некоректну дату: «%s».',
                $field,
                $value,
            ));
        }
    }

    /**
     * Значення приймаються і як повторювані параметри (?city=A&city=B),
     * і як список через кому (?city=A,B).
     *
     * @return list<string>
     */
    private function list(Request $request, string $key): array
    {
        $raw = $request->query->all()[$key] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function toType(string $value): BookingType
    {
        return BookingType::tryFrom($value) ?? throw InvalidFilterException::invalidEnum(sprintf(
            'Невідомий тип бронювання «%s». Доступні: scheduled, walk_in.',
            $value,
        ));
    }

    private function toStatus(string $value): BookingStatus
    {
        return BookingStatus::tryFrom($value) ?? throw InvalidFilterException::invalidEnum(sprintf(
            'Невідомий статус бронювання «%s».',
            $value,
        ));
    }
}
