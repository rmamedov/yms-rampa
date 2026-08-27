<?php

declare(strict_types=1);

namespace App\Tests\Domain\Notification;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Notification\SmsSegmentCalculator;
use App\Domain\Notification\TemplateRenderer;
use App\Domain\Notification\TemplateSamples;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Довжина SMS (NOT-07): кирилиця кодується в UCS-2 — 70 символів
 * в односегментному повідомленні і 67 у кожному сегменті склеєного.
 * Допускається до 3 сегментів, типовий текст має вкладатися у 2.
 */
#[CoversClass(SmsSegmentCalculator::class)]
final class SmsSegmentCalculatorTest extends TestCase
{
    private SmsSegmentCalculator $calculator;
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->calculator = new SmsSegmentCalculator();
        $this->renderer = new TemplateRenderer();
    }

    /**
     * @return iterable<string, array{NotificationTemplate}>
     */
    public static function smsTemplates(): iterable
    {
        foreach (NotificationTemplate::cases() as $template) {
            if ($template->supports(NotificationChannel::Sms)) {
                yield $template->code() => [$template];
            }
        }
    }

    /**
     * Головна перевірка NOT-07: типовий текст кожного SMS-шаблону
     * вкладається у 2 сегменти.
     */
    #[DataProvider('smsTemplates')]
    public function testTypicalSmsFitsIntoTwoSegments(NotificationTemplate $template): void
    {
        $rendered = $this->renderer->render($template, NotificationChannel::Sms, TemplateSamples::for($template));
        $segments = $this->calculator->segments($rendered->text);

        self::assertLessThanOrEqual(
            2,
            $segments,
            \sprintf(
                'Шаблон %s: типовий текст займає %d сегментів (%d символів), а має вкладатися у 2 (NOT-07).',
                $template->code(),
                $segments,
                mb_strlen($rendered->text, 'UTF-8'),
            ),
        );
    }

    /**
     * Навіть на довгих реальних даних SMS не має виходити за жорсткий
     * ліміт у 3 сегменти.
     */
    #[DataProvider('smsTemplates')]
    public function testLongRealisticDataStillFitsHardLimit(NotificationTemplate $template): void
    {
        $payload = TemplateSamples::for($template);
        $overrides = [
            'address' => 'просп. Академіка Глушкова, 13Б, корпус 2',
            'city' => 'Камʼянець-Подільський',
            'reason' => 'Технічні роботи на рампі магазину',
            'changes' => 'рампа 12 / авто AA1234BB',
        ];

        foreach ($overrides as $key => $value) {
            if (\array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        $rendered = $this->renderer->render($template, NotificationChannel::Sms, $payload);

        self::assertTrue(
            $this->calculator->fitsLimit($rendered->text),
            \sprintf(
                'Шаблон %s перевищив жорсткий ліміт NOT-07: %d сегментів.',
                $template->code(),
                $this->calculator->segments($rendered->text),
            ),
        );
    }

    public function testCyrillicTextIsTreatedAsUnicode(): void
    {
        self::assertTrue($this->calculator->isUnicode('Бронювання підтверджено'));
        self::assertFalse($this->calculator->isUnicode('Booking confirmed 12:30'));
    }

    public function testUnicodeSegmentBoundaries(): void
    {
        self::assertSame(0, $this->calculator->segments(''));
        self::assertSame(1, $this->calculator->segments(str_repeat('я', 70)));
        self::assertSame(2, $this->calculator->segments(str_repeat('я', 71)));
        self::assertSame(2, $this->calculator->segments(str_repeat('я', 134)));
        self::assertSame(3, $this->calculator->segments(str_repeat('я', 135)));
    }

    public function testLatinSegmentBoundaries(): void
    {
        self::assertSame(1, $this->calculator->segments(str_repeat('a', 160)));
        self::assertSame(2, $this->calculator->segments(str_repeat('a', 161)));
    }

    public function testExceedingThreeSegmentsIsRejected(): void
    {
        $tooLong = str_repeat('я', 202);

        self::assertFalse($this->calculator->fitsLimit($tooLong));
        self::assertSame(201, $this->calculator->maxLength($tooLong));
    }
}
