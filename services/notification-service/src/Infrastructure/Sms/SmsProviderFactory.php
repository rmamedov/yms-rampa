<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

use App\Domain\Transport\SmsProviderInterface;

/**
 * Вибір SMS-провайдера за env-конфігом (NOT-01).
 *
 * SMS_PROVIDER=turbosms|esputnik|null — зміна провайдера не потребує
 * жодної правки коду.
 */
final readonly class SmsProviderFactory
{
    public function __construct(
        private TurboSmsProvider $turboSms,
        private ESputnikSmsProvider $eSputnik,
        private NullSmsProvider $null,
        private string $providerName = 'null',
    ) {
    }

    public function create(): SmsProviderInterface
    {
        return match (mb_strtolower(trim($this->providerName))) {
            'turbosms' => $this->turboSms,
            'esputnik' => $this->eSputnik,
            default => $this->null,
        };
    }
}
