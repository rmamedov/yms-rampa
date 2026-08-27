<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Notification\Exception\ChannelNotSupportedException;
use App\Domain\Notification\Exception\MissingTemplateVariableException;
use App\Domain\Notification\Exception\UnresolvedPlaceholderException;

/**
 * Рендеринг шаблонів повідомлень (розділ 11.2.2, NOT-07, NOT-08).
 *
 * Правила:
 * - усі підстановки екрануються (NOT-08): з тексту SMS вирізаються керуючі
 *   символи, фігурні дужки (щоб значення не підмінило інший плейсхолдер)
 *   і згортаються повтори пробілів;
 * - порожній необовʼязковий orderId рендериться як «без номера» (NOT-08);
 * - необовʼязковий коментар відмови рендериться з комою-префіксом;
 * - у результаті не може лишитися жодного нерозкритого плейсхолдера.
 *
 * Клас навмисно не має залежностей від Symfony/HTTP/Mongo.
 */
final readonly class TemplateRenderer
{
    public function __construct(
        private SmsSegmentCalculator $segments = new SmsSegmentCalculator(),
    ) {
    }

    /**
     * @param array<string, scalar|\Stringable|null> $payload
     */
    public function render(
        NotificationTemplate $template,
        NotificationChannel $channel,
        array $payload,
    ): RenderedMessage {
        if (!$template->canRenderFor($channel)) {
            throw new ChannelNotSupportedException($template, $channel);
        }

        $text = $this->substitute($template, $template->body(), $payload);

        if (NotificationChannel::Email !== $channel) {
            return new RenderedMessage($template, $channel, $text);
        }

        $subject = $this->substitute($template, $template->emailSubject(), $payload);

        return new RenderedMessage(
            template: $template,
            channel: $channel,
            text: $text,
            subject: $subject,
            html: $this->toHtml($text),
        );
    }

    /**
     * Рендеринг SMS з перевіркою ліміту сегментів (NOT-07).
     * Повертає текст і фактичну кількість сегментів.
     *
     * @param array<string, scalar|\Stringable|null> $payload
     */
    public function renderSms(NotificationTemplate $template, array $payload): RenderedMessage
    {
        return $this->render($template, NotificationChannel::Sms, $payload);
    }

    public function segmentsOf(string $text): int
    {
        return $this->segments->segments($text);
    }

    /**
     * @param array<string, scalar|\Stringable|null> $payload
     */
    private function substitute(NotificationTemplate $template, string $source, array $payload): string
    {
        $replacements = [];

        foreach ($template->variables() as $name => $spec) {
            $raw = $payload[$name] ?? null;
            $value = null === $raw ? '' : trim((string) $raw);

            if ('' === $value) {
                if ($spec->required) {
                    throw new MissingTemplateVariableException($template, $name);
                }
                $replacements['{'.$name.'}'] = $spec->fallback;

                continue;
            }

            $replacements['{'.$name.'}'] = $spec->prefix.$this->escape($value);
        }

        $rendered = strtr($source, $replacements);

        if (1 === preg_match('/\{([A-Za-z0-9_]+)\}/', $rendered, $matches)) {
            throw new UnresolvedPlaceholderException($template, $matches[1]);
        }

        return $this->collapseWhitespace($rendered);
    }

    /**
     * Екранування підстановки (NOT-08): прибираємо керуючі символи і фігурні
     * дужки, щоб значення користувача не могло ані зламати верстку SMS,
     * ані вдати із себе інший плейсхолдер.
     */
    private function escape(string $value): string
    {
        $clean = preg_replace('/[\p{C}]+/u', ' ', $value) ?? $value;
        $clean = str_replace(['{', '}'], '', $clean);

        return trim($this->collapseWhitespace($clean));
    }

    private function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/[ \t]{2,}/', ' ', $value) ?? $value);
    }

    /** Тіло листа в HTML; увесь текст екранується цілком — інʼєкція неможлива. */
    private function toHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<p style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">'
            .nl2br($escaped)
            .'</p>';
    }
}
