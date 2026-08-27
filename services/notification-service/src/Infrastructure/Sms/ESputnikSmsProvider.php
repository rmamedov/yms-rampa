<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

use App\Domain\Transport\SmsProviderInterface;
use App\Domain\Transport\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Альтернативний адаптер SMS — eSputnik (NOT-01).
 *
 * Існує, щоб довести головну вимогу: провайдер міняється env-конфігом
 * (SMS_PROVIDER=turbosms|esputnik|null), а не правкою коду.
 */
final readonly class ESputnikSmsProvider implements SmsProviderInterface
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
            throw TransportException::permanent('Не налаштовано токен eSputnik (ESPUTNIK_TOKEN).');
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Basic '.$this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'from' => $this->sender,
                    'phoneNumbers' => [$phone],
                    'text' => $text,
                ],
                'timeout' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 500 || 429 === $status) {
                throw new TransportException(\sprintf('eSputnik відповів HTTP %d.', $status));
            }
            if ($status >= 400) {
                throw TransportException::permanent(\sprintf('eSputnik відхилив запит: HTTP %d.', $status));
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw new TransportException('Помилка звернення до eSputnik: '.$e->getMessage(), true, $e);
        }

        $results = $payload['results'] ?? null;
        if (\is_array($results) && isset($results[0]['id']) && \is_scalar($results[0]['id'])) {
            return (string) $results[0]['id'];
        }

        throw new TransportException('eSputnik повернув відповідь без ідентифікатора повідомлення.');
    }

    public function name(): string
    {
        return 'esputnik';
    }
}
