<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

use App\Domain\Transport\SmsProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SMS-провайдер-заглушка для dev і стенда (SMS_PROVIDER=null).
 *
 * Нічого не шле і не витрачає баланс. Текст у журнал не потрапляє:
 * шаблон NOT-T1 містить пароль водія (NOT-15).
 */
final readonly class NullSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function send(string $phone, string $text): string
    {
        $this->logger->info('NullSmsProvider: SMS не відправлено (режим заглушки)', [
            'phone' => $phone,
            'length' => mb_strlen($text, 'UTF-8'),
        ]);

        return 'null-sms-'.bin2hex(random_bytes(6));
    }

    public function name(): string
    {
        return 'null';
    }
}
