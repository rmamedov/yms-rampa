<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Auth\Exception\AccountLockedException;
use App\Domain\Auth\LoginAttempt;
use App\Domain\Auth\LoginThrottler;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryLoginAttemptRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-50 / DATA-20: 5 невдалих спроб за 15 хв → блокування на 15 хв;
 * лічильник скидається успішним логіном.
 */
#[CoversClass(LoginThrottler::class)]
#[CoversClass(LoginAttempt::class)]
final class LoginThrottlerTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryLoginAttemptRepository $attempts;
    private LoginThrottler $throttler;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-08-27T09:00:00+00:00');
        $this->attempts = new InMemoryLoginAttemptRepository();
        $this->throttler = new LoginThrottler($this->attempts, $this->clock);
    }

    public function testFiveFailuresLockAccountForFifteenMinutes(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->throttler->registerFailure('ivan@silpo.ua');
            $this->clock->advance('+1 minute');

            self::assertFalse($this->throttler->isLocked('ivan@silpo.ua'), "Блокування на {$i}-й спробі зарано");
            self::assertSame(5 - $i, $this->throttler->remainingAttempts('ivan@silpo.ua'));
        }

        $this->throttler->registerFailure('ivan@silpo.ua');

        self::assertTrue($this->throttler->isLocked('ivan@silpo.ua'));
        self::assertSame(0, $this->throttler->remainingAttempts('ivan@silpo.ua'));

        try {
            $this->throttler->assertNotLocked('ivan@silpo.ua');
            self::fail('Очікувалася відмова AUTH_ACCOUNT_LOCKED.');
        } catch (AccountLockedException $exception) {
            self::assertSame('AUTH_ACCOUNT_LOCKED', $exception->errorCode());
            self::assertSame(423, $exception->httpStatus());
            self::assertStringContainsString('15 хвилин', $exception->userMessage());
            self::assertSame(
                $this->clock->now()->modify('+15 minutes')->getTimestamp(),
                $exception->lockedUntil()->getTimestamp(),
            );
        }
    }

    /**
     * Критерій 3.9.3: через 15 хвилин логін знову можливий.
     */
    public function testLockExpiresAfterFifteenMinutes(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttler->registerFailure('ivan@silpo.ua');
        }

        self::assertTrue($this->throttler->isLocked('ivan@silpo.ua'));

        $this->clock->advance('+14 minutes');
        self::assertTrue($this->throttler->isLocked('ivan@silpo.ua'), 'До кінця блокування ще є час');

        $this->clock->advance('+2 minutes');
        self::assertFalse($this->throttler->isLocked('ivan@silpo.ua'));
        $this->throttler->assertNotLocked('ivan@silpo.ua');
    }

    /**
     * AUTH-50: вікно лічильника — 15 хв, розріджені спроби не блокують.
     */
    public function testFailuresOutsideWindowDoNotAccumulate(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttler->registerFailure('ivan@silpo.ua');
        }

        $this->clock->advance('+16 minutes');
        $this->throttler->registerFailure('ivan@silpo.ua');

        self::assertFalse($this->throttler->isLocked('ivan@silpo.ua'));
        self::assertSame(4, $this->throttler->remainingAttempts('ivan@silpo.ua'));
    }

    /**
     * AUTH-50: успішний логін скидає лічильник.
     */
    public function testSuccessfulLoginResetsCounter(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttler->registerFailure('ivan@silpo.ua');
        }

        $this->throttler->registerSuccess('ivan@silpo.ua', '10.0.0.1', 'PHPUnit');

        self::assertSame(5, $this->throttler->remainingAttempts('ivan@silpo.ua'));
        self::assertFalse($this->throttler->isLocked('ivan@silpo.ua'));
    }

    /**
     * Лічильник ведеться за логіном: блокування одного акаунта не зачіпає інший.
     */
    public function testLockIsPerLogin(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttler->registerFailure('ivan@silpo.ua');
        }

        self::assertTrue($this->throttler->isLocked('ivan@silpo.ua'));
        self::assertFalse($this->throttler->isLocked('olena@silpo.ua'));
    }

    /**
     * Крайовий випадок 3.6: логін нормалізується, тому реєстр і пробіли
     * не дають обійти блокування.
     */
    public function testLoginIsNormalizedBeforeCounting(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttler->registerFailure('  IVAN@Silpo.UA ');
        }

        self::assertTrue($this->throttler->isLocked('ivan@silpo.ua'));
    }

    /**
     * AUTH-52/AUTH-61: у журналі логін маскується.
     */
    public function testAuditTrailMasksLogin(): void
    {
        $this->throttler->registerFailure('ivan.petrenko@silpo.ua', '10.0.0.1', 'PHPUnit', 'invalid_credentials');

        $recorded = $this->attempts->all();
        self::assertCount(1, $recorded);
        self::assertSame('iv***********@silpo.ua', $recorded[0]->maskedLogin());
        self::assertSame('invalid_credentials', $recorded[0]->reason);
        self::assertFalse($recorded[0]->success);
        self::assertSame('UTC', $recorded[0]->at->getTimezone()->getName());
    }
}
