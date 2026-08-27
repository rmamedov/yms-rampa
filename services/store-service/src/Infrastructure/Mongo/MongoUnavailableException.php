<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Shared\DomainException;

/**
 * MongoDB недоступна: немає розширення ext-mongodb або сервера.
 * Виникає лише в рантаймі — автозавантаження і тести від цього не залежать.
 */
final class MongoUnavailableException extends DomainException
{
    public function httpStatus(): int
    {
        return 503;
    }

    public function title(): string
    {
        return 'Сховище недоступне';
    }

    public static function extensionMissing(): self
    {
        return new self(
            'Розширення PHP ext-mongodb не встановлено — Mongo-реалізацію репозиторіїв використати неможливо',
            'MONGO_EXTENSION_MISSING',
        );
    }
}
