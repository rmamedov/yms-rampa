<?php

declare(strict_types=1);

namespace App\Application\Outbox;

/**
 * Підсумок одного прогону релея — те, що команда друкує в журнал systemd.
 */
final readonly class RelayReport
{
    public function __construct(
        /** Скільки записів outbox позначено опублікованими. */
        public int $delivered,
        /** Скільки пакетів реально пішло сусідові. */
        public int $batches,
        /** Що сусід зробив з подіями. */
        public SinkReport $sink,
        /**
         * true — черга вичерпана до кінця; false — упертися в стелю пакетів,
         * решта поїде наступного прогону.
         */
        public bool $queueDrained,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->delivered;
    }
}
