<?php

declare(strict_types=1);

namespace App\Domain\Transport;

/**
 * Підтвердження прийому повідомлення провайдером.
 *
 * `providerMessageId` потрібен, щоб згодом зіставити delivery-report
 * і перевести сповіщення в статус delivered (NOT-03).
 */
final readonly class TransportReceipt
{
    public function __construct(
        public ?string $providerMessageId = null,
        public string $provider = '',
    ) {
    }
}
