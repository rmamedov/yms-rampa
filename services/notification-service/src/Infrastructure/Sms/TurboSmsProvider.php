<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

use App\Domain\Transport\SmsProviderInterface;
use App\Domain\Transport\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Адаптер TurboSMS (NOT-01).
 *
 * Ендпоінт, токен і підпис відправника конфігуруються через env
 * (TURBOSMS_ENDPOINT, TURBOSMS_TOKEN, TURBOSMS_SENDER) — код при зміні
 * провайдера не змінюється.
 *
 * Класифікація помилок для ретраїв (NOT-04):
 * - мережа, таймаут, HTTP 5xx, 429 → retryable;
 * - HTTP 4xx і відмова провайдера за змістом → permanent.
 */
final readonly class TurboSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        private string $token,
        private string $sender,
        private float $timeoutSeconds = 15.0,
    ) {
    }

    public function send(string $phone, string $text): string
    {
        if ('' === trim($this->token)) {
            throw TransportException::permanent('Не налаштовано токен TurboSMS (TURBOSMS_TOKEN).');
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'recipients' => [$phone],
                    'sms' => [
                        'sender' => $this->sender,
                        'text' => $text,
                    ],
                ],
                'timeout' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 500 || 429 === $status) {
                throw new TransportException(\sprintf('TurboSMS відповів HTTP %d.', $status));
            }
            if ($status >= 400) {
                throw TransportException::permanent(\sprintf('TurboSMS відхилив запит: HTTP %d.', $status));
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw new TransportException('Помилка звернення до TurboSMS: '.$e->getMessage(), true, $e);
        }

        return $this->extractMessageId($payload);
    }

    public function name(): string
    {
        return 'turbosms';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractMessageId(array $payload): string
    {
        $responseCode = $payload['response_code'] ?? null;

        if (null !== $responseCode && 0 !== (int) $responseCode) {
            throw TransportException::permanent(\sprintf(
                'TurboSMS повернув помилку %s: %s.',
                (string) $responseCode,
                \is_string($payload['response_status'] ?? null) ? $payload['response_status'] : 'без опису',
            ));
        }

        $result = $payload['response_result'] ?? null;
        if (\is_array($result) && isset($result[0]) && \is_array($result[0])) {
            $first = $result[0];
            if (isset($first['response_code']) && 0 !== (int) $first['response_code']) {
                throw TransportException::permanent(\sprintf(
                    'TurboSMS не прийняв номер: код %s.',
                    (string) $first['response_code'],
                ));
            }
            if (isset($first['message_id']) && \is_scalar($first['message_id'])) {
                return (string) $first['message_id'];
            }
        }

        throw new TransportException('TurboSMS повернув відповідь без ідентифікатора повідомлення.');
    }
}
