<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Запис журналу статусів (DATA-14): масив statusHistory тільки append-only,
 * будь-яка зміна status без відповідного запису вважається дефектом.
 *
 * КОЛОНКА «ХТО». Поле `by` — це ідентифікатор ОБЛІКОВОГО ЗАПИСУ, тобто UUID,
 * і показувати його людині безглуздо. Тому поруч зберігається роль виконавця
 * на момент дії та її контур — цього досить, щоб журнал читався
 * («Керівник магазину», «Водій», «Планове завдання»), не питаючи сусідні
 * сервіси під час рендеру.
 *
 * ЧОМУ НЕ ПІБ. booking-service не знає ані імені, ані пошти, ані телефону
 * співробітника: шлюз примусово підставляє рівно шість заголовків
 * ідентичності (X-User-Id, X-User-Role, X-Supplier-Id, X-Store-Ids,
 * X-Contour, X-Driver-Profile-Id) і жодного з іменем. Прочитати «ще один,
 * необовʼязковий» заголовок не можна: усе, що шлюз не перезаписує, підробляє
 * клієнт — а підроблене імʼя в журналі аудиту гірше за його відсутність.
 * Роль же приходить із перевіреного X-User-Role, тому їй можна вірити.
 */
final readonly class StatusChange
{
    public DateTimeImmutable $at;

    /**
     * @param array<string, mixed> $meta
     * @param Role|null            $byRole   роль виконавця; null — записи, зроблені
     *                                       до появи поля (DATA-02: читаємо всі версії)
     * @param bool                 $bySystem дію виконало планове завдання, а не людина
     */
    public function __construct(
        public ?BookingStatus $from,
        public BookingStatus $to,
        DateTimeImmutable $at,
        public string $by,
        public array $meta = [],
        public ?Role $byRole = null,
        public bool $bySystem = false,
    ) {
        $this->at = $at->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Канонічний спосіб створити запис: роль і контур беруться з актора,
     * тож розійтися з тим, хто справді виконав перехід, вони не можуть.
     *
     * @param array<string, mixed> $meta
     */
    public static function madeBy(
        ?BookingStatus $from,
        BookingStatus $to,
        DateTimeImmutable $at,
        Actor $actor,
        array $meta = [],
    ): self {
        return new self(
            from: $from,
            to: $to,
            at: $at,
            by: $actor->userId,
            meta: $meta,
            byRole: $actor->role,
            bySystem: $actor->system,
        );
    }

    /** Контур виконавця; null для записів без збереженої ролі. */
    public function contour(): ?Contour
    {
        if ($this->bySystem) {
            return Contour::System;
        }

        return $this->byRole?->contour();
    }

    /**
     * Людиночитане «Хто» для журналу дій. null означає чесне «невідомо»:
     * підставляти сюди ідентифікатор не можна — саме це й було дефектом.
     */
    public function actorLabel(): ?string
    {
        if ($this->bySystem) {
            return 'Планове завдання системи';
        }

        return $this->byRole?->label();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'from' => $this->from?->value,
            'to' => $this->to->value,
            'at' => $this->at->format('Y-m-d\TH:i:s\Z'),
            'by' => $this->by,
            'byRole' => $this->byRole?->value,
            'byContour' => $this->contour()?->value,
            'byLabel' => $this->actorLabel(),
        ];

        if ([] !== $this->meta) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }
}
