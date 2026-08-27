<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Domain\Booking\BookingRepository;
use App\Domain\Event\EventType;
use App\Domain\Outbox\OutboxRecord;
use App\Domain\Outbox\OutboxStore;
use App\Domain\Shared\Clock;

/**
 * Релей transactional outbox (DATA-16).
 *
 * Схема DATA-16 завжди передбачала два кроки: подія пишеться в outbox у тій
 * самій транзакції, що й бронювання, а окремий релей публікує її споживачам.
 * Другого кроку в системі не існувало — саме тому read-моделі analytics-service
 * лишалися порожніми, і вся аналітика показувала «Немає даних за обраний
 * період». Цей клас і є той відсутній релей.
 *
 * Транспорт схований за портом AnalyticsEventSink: сьогодні це HTTP-виклик до
 * analytics-service, завтра — публікація в RabbitMQ. Для релея різниці немає.
 *
 * СЕМАНТИКА ДОСТАВКИ — at-least-once, як і задумано в DATA-16:
 *   1. читаємо порцію неопублікованих записів у порядку виникнення;
 *   2. віддаємо пакет сусідові;
 *   3. лише ПІСЛЯ підтвердження позначаємо записи опублікованими.
 * Якщо крок 2 упав — записи лишаються неопублікованими і поїдуть наступного
 * прогону. Повторна доставка безпечна: analytics-service дедуплікує події за
 * eventId.
 *
 * ЩО РОБИТЬСЯ З ПОДІЯМИ, ЯКИХ СПОЖИВАЧ НЕ ПРИЙНЯВ (сирота, нерозбірливий
 * payload). Опублікованими вони НЕ позначаються — це була найдорожча помилка
 * першої версії: перший прогін на стенді «доставив» 1301 подію, з яких
 * застосовано 765, а решта зникла з черги без сліду і без можливості
 * перепровести. Тепер такий запис іде в КАРАНТИН (OutboxStore::markFailed):
 * він не блокує чергу, зберігає причину й лічильник спроб, лишається видимим
 * у звіті кожного прогону і повертається в чергу командою
 * `yms:outbox:relay --requeue-failed` після виправлення формату подій.
 *
 * Про добір поля `city` для подій, записаних до появи цього релея, —
 * див. self::withCity.
 */
final readonly class OutboxRelay
{
    /**
     * Скільки записів іде в одному пакеті. 200 — компроміс: тіло запиту
     * лишається в межах client_max_body_size внутрішнього шлюзу (1 МБ),
     * а сотня подій за хвилину проходить одним викликом.
     */
    public const int DEFAULT_BATCH_SIZE = 200;

    /**
     * Стеля пакетів за один прогін. Потрібна, щоб перший запуск на стенді з
     * накопиченою історією не крутився нескінченно і не тримав systemd-таймер:
     * решта поїде наступною хвилиною.
     */
    public const int DEFAULT_MAX_BATCHES = 25;

    public function __construct(
        private OutboxStore $outbox,
        private AnalyticsEventSink $sink,
        private Clock $clock,
        /**
         * Потрібен рівно для сумісності зі старими записами outbox
         * (див. self::withCity). Для нових подій не читається жодного разу.
         */
        private ?BookingRepository $bookings = null,
    ) {
    }

    /**
     * @throws \App\Domain\Exception\UpstreamUnavailableException сусід недоступний:
     *         частина пакетів могла доїхати, решта лишається в черзі
     */
    public function relay(
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        int $maxBatches = self::DEFAULT_MAX_BATCHES,
    ): RelayReport {
        $delivered = 0;
        $quarantined = 0;
        $batches = 0;
        $sink = new SinkReport();
        // Розрізняємо «черга скінчилася» і «упертися в стелю пакетів»:
        // у другому випадку команда має сказати, що решта поїде наступного разу.
        $queueDrained = false;

        while ($batches < $maxBatches) {
            $records = $this->outbox->pending($batchSize);

            if ([] === $records) {
                $queueDrained = true;

                break;
            }

            $report = $this->sink->deliver(array_map($this->envelope(...), $records));
            $sink = $sink->plus($report);
            ++$batches;

            $now = $this->clock->now();

            foreach ($records as $index => $record) {
                $outcome = $report->outcomeAt($index);

                // Присуду немає — контракт порушено. Транспорт це вже мав
                // відсікти винятком; якщо ні, запис лишається в черзі
                // недоторканим: краще повторна доставка, ніж утрата.
                if (null === $outcome) {
                    continue;
                }

                if ($outcome->isDelivered()) {
                    $this->outbox->markPublished($record->id, $now);
                    ++$delivered;

                    continue;
                }

                // Сирота або відхилення: у карантин із причиною, а НЕ в
                // «опубліковані». Запис лишається видимим і придатним до
                // перепроведення (--requeue-failed) після виправлення payload.
                $this->outbox->markFailed(
                    $record->id,
                    \sprintf('%s: %s', $outcome->label(), $report->reasonAt($index) ?? 'причину не вказано'),
                    $now,
                );
                ++$quarantined;
            }

            // Порція неповна — черга вичерпана, наступний запит був би марним.
            if (\count($records) < $batchSize) {
                $queueDrained = true;

                break;
            }
        }

        return new RelayReport(
            delivered: $delivered,
            quarantined: $quarantined,
            batches: $batches,
            sink: $sink,
            queueDrained: $queueDrained,
            quarantineTotal: $this->outbox->countQuarantined(),
        );
    }

    /**
     * Повернути карантин у чергу — після того, як формат подій виправлено.
     *
     * @return int скільки записів повернено
     */
    public function requeueQuarantined(): int
    {
        return $this->outbox->requeueFailed();
    }

    /**
     * Конверт події в тому вигляді, якого чекає analytics-service
     * (App\Domain\Projection\DomainEvent::fromArray сусіда).
     *
     * Дві розбіжності з форматом outbox, які саме тут і зводяться:
     *   - `eventId` у booking-service немає: ключем ідемпотентності стає _id
     *     запису outbox — він стабільний, тож повторна доставка того самого
     *     запису розпізнається сусідом як дублікат;
     *   - поле назви події зветься `eventType`, а сусід читає `name`.
     * Обидві — робота перекладача на межі, а не «покращення» контракту.
     *
     * @return array<string, mixed>
     */
    private function envelope(OutboxRecord $record): array
    {
        return [
            'eventId' => $record->id,
            'name' => $record->event->type->value,
            'occurredAt' => $record->event->occurredAt->format('Y-m-d\TH:i:s\Z'),
            'payload' => $this->withCity($record),
        ];
    }

    /**
     * СУМІСНІСТЬ ЗІ СТАРИМИ ЗАПИСАМИ OUTBOX.
     *
     * Поле `city` зʼявилося в подіях бронювання разом із цим релеєм, а на
     * стенді outbox уже містить події, записані до того. Для аналітики `city`
     * обовʼязковий: без нього BookingCreated не створює факт узагалі, тож усі
     * наявні бронювання лишилися б поза KPI, а решта їхніх подій стала б
     * сиротами — тобто вже виправлена аналітика знову виглядала б порожньою.
     *
     * Тому місто добирається зі СНАПШОТА філії того самого бронювання
     * (booking.storeSnapshot.city). Це не «покращення» чужого контракту:
     * booking-service володіє і подією, і документом, з якого вона зроблена,
     * тож просто дописує власне поле, яке колись забув записати.
     *
     * Читання відбувається лише для BookingCreated без `city`. Коли черга
     * старих записів вичерпається, цей метод перестане робити щось узагалі —
     * і його можна буде прибрати разом із аргументом $bookings.
     *
     * @return array<string, mixed>
     */
    private function withCity(OutboxRecord $record): array
    {
        $payload = $record->event->payload;

        if (EventType::BookingCreated !== $record->event->type) {
            return $payload;
        }

        $city = $payload['city'] ?? null;

        if (\is_string($city) && '' !== $city) {
            return $payload;
        }

        // Бронювання могло зникнути (жорстке чищення стенду) — тоді лишаємо
        // подію як є: сусід чесно поверне її в переліку `failed`.
        $booking = $this->bookings?->find($record->event->aggregateId);

        // Бронювання зникло (жорстке чищення стенду) або сама філія не має
        // міста в довіднику — вигадувати нічого не будемо. Аналітика такий
        // факт приймає і відносить у групу «Місто не вказано».
        if (null === $booking || '' === $booking->storeSnapshot->city) {
            return $payload;
        }

        $payload['city'] = $booking->storeSnapshot->city;

        return $payload;
    }
}
