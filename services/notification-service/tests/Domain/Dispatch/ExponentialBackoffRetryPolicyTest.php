<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dispatch;

use App\Domain\Dispatch\ExponentialBackoffRetryPolicy;
use App\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Політика ретраїв (NOT-04): до N спроб з експоненційним backoff.
 * Значення за замовчуванням дають зафіксовані специфікацією
 * інтервали 1, 5, 15 хв.
 */
#[CoversClass(ExponentialBackoffRetryPolicy::class)]
final class ExponentialBackoffRetryPolicyTest extends TestCase
{
    public function testDefaultScheduleMatchesSpecificationIntervals(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(maxAttempts: 4);

        // 60 → 60×5=300 → 60×25=1500, обрізане стелею до 900.
        self::assertSame([60, 300, 900], $policy->schedule());
    }

    public function testDefaultConfigurationUsesThreeAttempts(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();

        self::assertSame(3, $policy->maxAttempts());
        self::assertSame([60, 300], $policy->schedule());
    }

    public function testDelayGrowsExponentiallyAndIsCapped(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(
            maxAttempts: 10,
            initialDelaySeconds: 60,
            multiplier: 5.0,
            maxDelaySeconds: 900,
        );

        self::assertSame(60, $policy->delayForAttempt(1));
        self::assertSame(300, $policy->delayForAttempt(2));
        self::assertSame(900, $policy->delayForAttempt(3));
        self::assertSame(900, $policy->delayForAttempt(9));
    }

    public function testAttemptNumberIsNormalised(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();

        self::assertSame(60, $policy->delayForAttempt(0));
        self::assertSame(60, $policy->delayForAttempt(-5));
    }

    public function testShouldRetryStopsAtMaxAttempts(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(maxAttempts: 3);

        self::assertTrue($policy->shouldRetry(1));
        self::assertTrue($policy->shouldRetry(2));
        self::assertFalse($policy->shouldRetry(3));
        self::assertFalse($policy->shouldRetry(4));
    }

    public function testMaxAttemptsIsConfigurable(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(maxAttempts: 6, initialDelaySeconds: 30, multiplier: 2.0, maxDelaySeconds: 600);

        self::assertSame(6, $policy->maxAttempts());
        self::assertSame([30, 60, 120, 240, 480], $policy->schedule());
    }

    public function testZeroAttemptsIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Кількість спроб відправки має бути щонайменше 1.');

        new ExponentialBackoffRetryPolicy(maxAttempts: 0);
    }

    public function testCeilingBelowInitialDelayIsRejected(): void
    {
        $this->expectException(DomainException::class);

        new ExponentialBackoffRetryPolicy(initialDelaySeconds: 600, maxDelaySeconds: 60);
    }

    public function testMultiplierBelowOneIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Множник backoff має бути не меншим за 1.');

        new ExponentialBackoffRetryPolicy(multiplier: 0.5);
    }
}
