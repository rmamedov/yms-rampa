<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\ConfigurationPresenter;
use App\Application\Dto\Payload;
use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\CalendarExceptionType;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\SlotSize;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Domain\Configuration\TimeInterval;
use App\Domain\Event\EventPublisher;
use App\Domain\Event\StoreConfigChanged;
use App\Domain\Shared\Clock;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\Timezone;
use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;

/**
 * Версіонована конфігурація магазину: вкладки «Прийом поставок», «Слоти», «Обмеження»
 * (5.3.2–5.3.4, DATA-09, DATA-10).
 *
 * STC-60: зміна сітки слотів застосовується «з дати X» — не раніше завтра
 * за локальним часом магазину; до дати X діє попередня версія.
 */
final readonly class StoreConfigurationService
{
    public function __construct(
        private StoreConfigurationRepository $configurations,
        private StoreCatalogService $catalog,
        private EventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(string $storeId): array
    {
        $this->catalog->requireBranch($storeId);

        return array_map(
            static fn (StoreConfiguration $c): array => ConfigurationPresenter::configuration($c),
            $this->configurations->findAllForStore($storeId),
        );
    }

    /**
     * Чинна конфігурація магазину на поточний момент.
     *
     * @return array<string, mixed>
     */
    public function current(string $storeId): array
    {
        $this->catalog->requireBranch($storeId);
        $config = $this->configurations->findEffectiveAt($storeId, $this->clock->now());

        if (!$config instanceof StoreConfiguration) {
            throw NotFoundException::configuration($storeId);
        }

        return ConfigurationPresenter::configuration($config);
    }

    /**
     * DATA-09: створення НОВОЇ версії конфігурації; наявна версія ніколи не оновлюється.
     *
     * @return array<string, mixed>
     */
    public function createVersion(string $storeId, Payload $payload, ?string $createdBy = null): array
    {
        $this->catalog->requireBranch($storeId);

        $now = $this->clock->now();
        $version = $this->configurations->nextVersion($storeId);
        $isFirstVersion = 1 === $version;

        $effectiveFrom = $payload->dateTime('effectiveFrom')
            ?? ($isFirstVersion ? $this->todayLocalMidnight($now) : $this->tomorrowLocalMidnight($now));

        $this->assertEffectiveFrom($effectiveFrom, $now, $isFirstVersion);

        $slotSize = SlotSize::fromMinutes($payload->requireInt('slotSizeMinutes'));

        $config = new StoreConfiguration(
            id: Uuid::v4(),
            storeId: $storeId,
            version: $version,
            effectiveFrom: $effectiveFrom,
            receivingWindows: $this->windows($payload),
            slotSize: $slotSize,
            ramps: $this->ramps($payload),
            maxVehicleWeightTons: $payload->requireFloat('maxVehicleWeightTons'),
            leadTimeMinutes: $payload->int('leadTimeMinutes', StoreConfiguration::LEAD_TIME_DEFAULT) ?? StoreConfiguration::LEAD_TIME_DEFAULT,
            bookingHorizonDays: $payload->int('bookingHorizonDays', StoreConfiguration::HORIZON_DEFAULT_DAYS) ?? StoreConfiguration::HORIZON_DEFAULT_DAYS,
            noShowGraceMinutes: $payload->int('noShowGraceMinutes', StoreConfiguration::NO_SHOW_GRACE_DEFAULT) ?? StoreConfiguration::NO_SHOW_GRACE_DEFAULT,
            holdMaxMinutes: $payload->int('holdMaxMinutes', StoreConfiguration::HOLD_MAX_DEFAULT) ?? StoreConfiguration::HOLD_MAX_DEFAULT,
            calendarExceptions: $this->calendarExceptions($payload, $now),
            createdBy: $createdBy,
            createdAt: $now,
        );

        $this->configurations->save($config);
        $this->events->publish(new StoreConfigChanged($storeId, 'configuration', $config->version, $effectiveFrom, $now));

        return ConfigurationPresenter::configuration($config);
    }

    /**
     * STC-60: зміна сітки слотів набирає чинності не раніше завтра — щоб
     * не зламати вже наявні бронювання на сьогодні.
     *
     * Виняток — ПЕРША конфігурація магазину: доти сітки не існувало, отже
     * не існує й бронювань, які треба захищати. Без цього винятку філію
     * неможливо налаштувати й активувати того самого дня, чого вимагає
     * сценарій онбордингу магазину E2E-01.
     */
    private function assertEffectiveFrom(
        \DateTimeImmutable $effectiveFrom,
        \DateTimeImmutable $now,
        bool $isFirstVersion,
    ): void {
        $earliest = $isFirstVersion ? $this->todayLocalMidnight($now) : $this->tomorrowLocalMidnight($now);

        if ($effectiveFrom < $earliest) {
            throw ValidationException::config(
                \sprintf(
                    'Зміни сітки слотів можуть набирати чинності не раніше %s',
                    $earliest->setTimezone(Timezone::storeLocal())->format('Y-m-d'),
                ),
                ['effectiveFrom' => $isFirstVersion
                    ? 'Дата набрання чинності — не раніше сьогодні'
                    : 'Дата набрання чинності — не раніше завтра'],
            );
        }
    }

    private function tomorrowLocalMidnight(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now
            ->setTimezone(Timezone::storeLocal())
            ->modify('tomorrow midnight')
            ->setTimezone(Timezone::storage());
    }

    private function todayLocalMidnight(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now
            ->setTimezone(Timezone::storeLocal())
            ->modify('today midnight')
            ->setTimezone(Timezone::storage());
    }

    /**
     * @return list<ReceivingWindow>
     */
    private function windows(Payload $payload): array
    {
        $windows = [];

        foreach ($payload->objectList('receivingWindows') as $raw) {
            $item = new Payload($raw);
            $intervals = [];

            foreach ($item->objectList('intervals') as $rawInterval) {
                $interval = new Payload($rawInterval);
                $intervals[] = new TimeInterval($interval->requireString('from'), $interval->requireString('to'));
            }

            $windows[] = new ReceivingWindow($item->requireInt('dayOfWeek'), $intervals);
        }

        return $windows;
    }

    /**
     * @return list<Ramp>
     */
    private function ramps(Payload $payload): array
    {
        $ramps = [];

        foreach ($payload->objectList('ramps') as $raw) {
            $item = new Payload($raw);
            $number = $item->requireInt('number');

            $ramps[] = new Ramp(
                rampId: $item->string('rampId') ?? \sprintf('r%d', $number),
                number: $number,
                name: $item->string('name'),
                active: $item->bool('active', true) ?? true,
            );
        }

        return $ramps;
    }

    /**
     * @return list<CalendarException>
     */
    private function calendarExceptions(Payload $payload, \DateTimeImmutable $now): array
    {
        $today = Timezone::localDate($now);
        $exceptions = [];

        foreach ($payload->objectList('calendarExceptions') as $raw) {
            $item = new Payload($raw);
            $type = CalendarExceptionType::tryFrom($item->requireString('type'));

            if (!$type instanceof CalendarExceptionType) {
                throw ValidationException::config(
                    'Тип винятку календаря має бути closed або custom',
                    ['type' => 'Допустимі значення: closed, custom'],
                );
            }

            $intervals = [];

            foreach ($item->objectList('intervals') as $rawInterval) {
                $interval = new Payload($rawInterval);
                $intervals[] = new TimeInterval($interval->requireString('from'), $interval->requireString('to'));
            }

            $exception = new CalendarException(
                date: $item->requireString('date'),
                type: $type,
                reason: $item->string('reason') ?? '',
                intervals: $intervals,
            );

            // STC-13: не в минулому і не далі ніж на 365 днів уперед.
            $exception->assertWithinAllowedRange($today);

            $exceptions[] = $exception;
        }

        return $exceptions;
    }
}
