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
 * ЧОМУ ЗАПИС ПОЗНАЧАЄТЬСЯ ОПУБЛІКОВАНИМ НАВІТЬ ПРИ `orphan` / `failed`.
 * Черга впорядкована за часом виникнення, тому подія, для якої немає
 * BookingCreated, уже ніколи його не дочекається — залишити її неопублікованою
 * означало б назавжди зупинити релей на одному непридатному записі і знову
 * лишити аналітику без даних. Тому пакет, який сусід ПРИЙНЯВ, вважається
 * доставленим, а проблемні події гучно потрапляють у звіт і в журнал команди.
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

            $sink = $sink->plus($this->sink->deliver(array_map($this->envelope(...), $records)));
            ++$batches;

            $publishedAt = $this->clock->now();

            foreach ($records as $record) {
                $this->outbox->markPublished($record->id, $publishedAt);
                ++$delivered;
            }

            // Порція неповна — черга вичерпана, наступний запит був би марним.
            if (\count($records) < $batchSize) {
                $queueDrained = true;

                break;
            }
        }

        return new RelayReport(
            delivered: $delivered,
            batches: $batches,
            sink: $sink,
            queueDrained: $queueDrained,
        );
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

        if (null === $booking) {
            return $payload;
        }

        $payload['city'] = $booking->storeSnapshot->city;

        return $payload;
    }
}
