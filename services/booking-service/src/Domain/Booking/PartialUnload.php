<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Ознака часткового розвантаження з обовʼязковою причиною з довідника (ST-03).
 * Входить у подію UnloadingCompleted і в аналітику.
 */
final readonly class PartialUnload
{
    public function __construct(
        public PartialUnloadReason $reason,
        public ?string $comment = null,
        public bool $flag = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flag' => $this->flag,
            'reason' => $this->reason->value,
            'comment' => $this->comment,
        ];
    }
}
