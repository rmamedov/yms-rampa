<?php

declare(strict_types=1);

namespace App\Application\Outbox;

/**
 * Підсумок одного прогону релея — те, що команда друкує в журнал systemd.
 */
final readonly class RelayReport
{
    public function __construct(
        /** Скільки записів споживач ПРИЙНЯВ — саме вони пішли з черги. */
        public int $delivered,
        /** Скільки записів цього прогону відправлено в карантин. */
        public int $quarantined,
        /** Скільки пакетів реально пішло споживачеві. */
        public int $batches,
        /** Присуд за кожною подією. */
        public SinkReport $sink,
        /**
         * true — черга вичерпана до кінця; false — упертися в стелю пакетів,
         * решта поїде наступного прогону.
         */
        public bool $queueDrained,
        /** Скільки записів у карантині ВСЬОГО, разом із попередніми прогонами. */
        public int $quarantineTotal = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->delivered && 0 === $this->quarantined;
    }
}
