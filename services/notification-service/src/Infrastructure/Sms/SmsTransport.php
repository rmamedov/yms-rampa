<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\SmsSegmentCalculator;
use App\Domain\Transport\NotificationTransport;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\SmsProviderInterface;
use App\Domain\Transport\TransportException;
use App\Domain\Transport\TransportReceipt;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SMS-транспорт (NOT-01).
 *
 * Сам нічого не знає про конкретного провайдера — працює через
 * SmsProviderInterface, тому TurboSMS змінюється на eSputnik самим лише
 * env-конфігом.
 *
 * Перед відправкою перевіряє ліміт NOT-07: більше 3 сегментів провайдер
 * не приймає, тому таке повідомлення відхиляється як невиправна помилка
 * (ретраї не допоможуть).
 */
final readonly class SmsTransport implements NotificationTransport
{
    public function __construct(
        private SmsProviderInterface $provider,
        private SmsSegmentCalculator $segments = new SmsSegmentCalculator(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        return NotificationChannel::Sms === $channel;
    }

    public function send(OutgoingMessage $message): TransportReceipt
    {
        $segments = $this->segments->segments($message->text);

        if ($segments > SmsSegmentCalculator::MAX_SEGMENTS) {
            throw TransportException::permanent(\sprintf(
                'SMS перевищує ліміт NOT-07: %d сегментів замість максимум %d.',
                $segments,
                SmsSegmentCalculator::MAX_SEGMENTS,
            ));
        }

        $phone = $this->normalizePhone($message->recipient);
        $messageId = $this->provider->send($phone, $message->text);

        // У журнал іде лише довжина: текст може містити пароль водія (NOT-15).
        $this->logger->info('SMS передано провайдеру', [
            'notificationId' => $message->notificationId,
            'template' => $message->templateCode,
            'provider' => $this->provider->name(),
            'segments' => $segments,
        ]);

        return new TransportReceipt(providerMessageId: $messageId, provider: $this->provider->name());
    }

    public function name(): string
    {
        return 'sms:'.$this->provider->name();
    }

    /**
     * Українські провайдери приймають номер у форматі 380XXXXXXXXX —
     * без «+» і без роздільників.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (10 === \strlen($digits) && str_starts_with($digits, '0')) {
            $digits = '38'.$digits;
        }

        if (12 !== \strlen($digits) || !str_starts_with($digits, '380')) {
            throw TransportException::permanent(
                \sprintf('Некоректний номер телефону отримувача: «%s».', $phone),
            );
        }

        return $digits;
    }
}
