<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Slot\SlotFact;
use App\Domain\Slot\SlotState;

/**
 * Мапер SlotFact ↔ документ MongoDB (колекція slot_facts).
 */
final readonly class SlotFactDocumentMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toDocument(SlotFact $slot): array
    {
        return [
            '_id' => $slot->slotId,
            'slotId' => $slot->slotId,
            'storeId' => $slot->storeId,
            'city' => $slot->city,
            'rampId' => $slot->rampId,
            'start' => $slot->start,
            'end' => $slot->end,
            'state' => $slot->state->value,
            'minutes' => $slot->minutes(),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public function fromDocument(array $document): SlotFact
    {
        return new SlotFact(
            slotId: (string) $document['slotId'],
            storeId: (string) $document['storeId'],
            city: (string) $document['city'],
            rampId: (string) $document['rampId'],
            start: BsonCodec::requireDate($document['start'] ?? null, 'start'),
            end: BsonCodec::requireDate($document['end'] ?? null, 'end'),
            state: SlotState::from((string) $document['state']),
        );
    }
}
