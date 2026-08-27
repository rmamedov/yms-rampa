<?php

declare(strict_types=1);

namespace App\Tests\Domain\Notification;

use App\Domain\Notification\Exception\ChannelNotSupportedException;
use App\Domain\Notification\Exception\MissingTemplateVariableException;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Notification\TemplateRenderer;
use App\Domain\Notification\TemplateSamples;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Рендеринг шаблонів розділу 11.2.2 (NOT-08).
 */
#[CoversClass(TemplateRenderer::class)]
#[CoversClass(NotificationTemplate::class)]
final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    /**
     * @return iterable<string, array{NotificationTemplate}>
     */
    public static function templates(): iterable
    {
        foreach (NotificationTemplate::cases() as $template) {
            yield $template->code() => [$template];
        }
    }

    #[DataProvider('templates')]
    public function testEveryTemplateRendersWithoutLeftoverPlaceholders(NotificationTemplate $template): void
    {
        $payload = TemplateSamples::for($template);

        foreach ($template->channels() as $channel) {
            $rendered = $this->renderer->render($template, $channel, $payload);

            self::assertNotSame('', $rendered->text, 'Шаблон '.$template->code().' не має бути порожнім.');
            self::assertDoesNotMatchRegularExpression(
                '/\{[A-Za-z0-9_]+\}/',
                $rendered->text,
                'У тексті '.$template->code().' лишився нерозкритий плейсхолдер.',
            );
        }
    }

    #[DataProvider('templates')]
    public function testEveryTemplateDeclaresAtLeastOneChannelAndVariable(NotificationTemplate $template): void
    {
        self::assertNotEmpty($template->channels());
        self::assertNotEmpty($template->variables());
        self::assertNotSame('', $template->emailSubject());
    }

    public function testDriverPasswordSmsMatchesSpecificationText(): void
    {
        $rendered = $this->renderer->render(
            NotificationTemplate::DriverPassword,
            NotificationChannel::Sms,
            ['phone' => '+380671234567', 'password' => 'Xk7m2Qp9', 'url' => 'https://yms.silpo.ua/d'],
        );

        self::assertSame(
            'Сільпо YMS Рампа. Ваш логін: +380671234567, пароль: Xk7m2Qp9. Вхід: https://yms.silpo.ua/d. Нікому не повідомляйте пароль.',
            $rendered->text,
        );
    }

    public function testBookingConfirmedRendersOrderNumber(): void
    {
        $rendered = $this->renderer->render(
            NotificationTemplate::BookingConfirmed,
            NotificationChannel::Sms,
            TemplateSamples::for(NotificationTemplate::BookingConfirmed),
        );

        self::assertStringContainsString('Бронювання підтверджено: 05.09.2026 14:30', $rendered->text);
        self::assertStringContainsString('філія №1998, Київ, вул. Хрещатик, 1, рампа 3', $rendered->text);
        self::assertStringContainsString('Замовлення 12345.', $rendered->text);
    }

    /**
     * NOT-08: порожній необовʼязковий orderId рендериться як «без номера».
     */
    public function testEmptyOrderIdRendersAsWithoutNumber(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingConfirmed);
        $payload['orderId'] = '';

        $rendered = $this->renderer->render(NotificationTemplate::BookingConfirmed, NotificationChannel::Sms, $payload);

        self::assertStringContainsString('Замовлення без номера.', $rendered->text);
        self::assertStringNotContainsString('{orderId}', $rendered->text);
    }

    public function testMissingOrderIdKeyAlsoRendersAsWithoutNumber(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingConfirmed);
        unset($payload['orderId']);

        $rendered = $this->renderer->render(NotificationTemplate::BookingConfirmed, NotificationChannel::Sms, $payload);

        self::assertStringContainsString('Замовлення без номера.', $rendered->text);
    }

    /**
     * NOT-T8: «Причина: {причина}{, коментар}» — коментар необовʼязковий
     * і додається разом з комою.
     */
    public function testRejectionCommentIsOptionalAndPrefixedWithComma(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingRejected);

        $withComment = $this->renderer->render(NotificationTemplate::BookingRejected, NotificationChannel::Email, $payload);
        self::assertStringContainsString('Причина: Невідповідність документів, Немає ТТН.', $withComment->text);

        $payload['comment'] = null;
        $withoutComment = $this->renderer->render(NotificationTemplate::BookingRejected, NotificationChannel::Email, $payload);
        self::assertStringContainsString('Причина: Невідповідність документів.', $withoutComment->text);
        self::assertStringNotContainsString(',.', $withoutComment->text);
    }

    public function testMissingRequiredVariableIsRejected(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingCancelled);
        unset($payload['reason']);

        $this->expectException(MissingTemplateVariableException::class);
        $this->expectExceptionMessage('Не передано обовʼязкову підстановку «reason» для шаблону NOT-T5.');

        $this->renderer->render(NotificationTemplate::BookingCancelled, NotificationChannel::Sms, $payload);
    }

    /**
     * NOT-08: підстановки екрануються — значення не може підмінити інший
     * плейсхолдер або внести керуючі символи.
     */
    public function testSubstitutionsAreEscaped(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingCancelled);
        $payload['reason'] = "Аварія {url}\nна рампі";

        $rendered = $this->renderer->render(NotificationTemplate::BookingCancelled, NotificationChannel::Sms, $payload);

        self::assertStringContainsString('Аварія url на рампі', $rendered->text);
        self::assertStringNotContainsString("\n", $rendered->text);
        // Справжній {url} підставився рівно один раз — значення його не продублювало.
        self::assertSame(1, substr_count($rendered->text, 'https://yms.silpo.ua/b'));
    }

    public function testEmailRenderingProducesSubjectAndEscapedHtml(): void
    {
        $payload = TemplateSamples::for(NotificationTemplate::BookingRejected);
        $payload['comment'] = '<script>alert(1)</script>';

        $rendered = $this->renderer->render(NotificationTemplate::BookingRejected, NotificationChannel::Email, $payload);

        self::assertNotNull($rendered->subject);
        self::assertStringContainsString('Відмова в прийомі — філія №1998', $rendered->subject);
        self::assertNotNull($rendered->html);
        self::assertStringNotContainsString('<script>', $rendered->html);
        self::assertStringContainsString('&lt;script&gt;', $rendered->html);
    }

    /**
     * NOT-01: Viber — фаза 2, текстів для нього немає.
     */
    public function testViberChannelIsRejected(): void
    {
        $this->expectException(ChannelNotSupportedException::class);

        $this->renderer->render(
            NotificationTemplate::BookingConfirmed,
            NotificationChannel::Viber,
            TemplateSamples::for(NotificationTemplate::BookingConfirmed),
        );
    }

    /**
     * NOT-05: перелік критичних шаблонів зафіксовано специфікацією.
     */
    public function testCriticalTemplatesMatchSpecification(): void
    {
        $critical = array_values(array_map(
            static fn (NotificationTemplate $t): string => $t->code(),
            array_filter(NotificationTemplate::cases(), static fn (NotificationTemplate $t): bool => $t->isCritical()),
        ));

        self::assertSame(
            ['NOT-T1', 'NOT-T2', 'NOT-T5', 'NOT-T7', 'NOT-T8', 'NOT-T9', 'NOT-T14'],
            $critical,
        );
    }

    /**
     * Маршрутизація каналів із таблиці 11.2.2.
     */
    public function testChannelRoutingMatchesSpecification(): void
    {
        self::assertSame([NotificationChannel::Sms], NotificationTemplate::DriverPassword->channels());
        self::assertSame([NotificationChannel::Sms], NotificationTemplate::Reminder2h->channels());
        self::assertSame([NotificationChannel::Sms], NotificationTemplate::BookingDelayed->channels());
        self::assertSame([NotificationChannel::Email], NotificationTemplate::BookingRejected->channels());
        self::assertSame(
            [NotificationChannel::Sms, NotificationChannel::Email],
            NotificationTemplate::BookingConfirmed->channels(),
        );
    }

    /**
     * NOT-15: пароль позначений як секретна підстановка.
     */
    public function testOnlyDriverPasswordDeclaresSensitiveVariable(): void
    {
        self::assertSame(['password'], NotificationTemplate::DriverPassword->sensitiveVariables());

        foreach (NotificationTemplate::cases() as $template) {
            if (NotificationTemplate::DriverPassword === $template) {
                continue;
            }
            self::assertSame([], $template->sensitiveVariables(), $template->code());
        }
    }
}
