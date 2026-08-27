<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Hold\SlotHoldService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Booking\Exception\SlotAlreadyBookedException;
use App\Domain\Hold\Exception\HoldExpiredException;
use App\Domain\Hold\Exception\HoldNotOwnedException;
use App\Domain\Hold\Exception\SlotHeldException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Холди слота HOLD-01..HOLD-04 на реалізації в памʼяті —
 * повний функціональний еквівалент Redis SET NX.
 */
#[CoversClass(SlotHoldService::class)]
final class SlotHoldTest extends TestCase
{
    /** HOLD-01: TTL холду — 5 хв. */
    public function testHoldIsCreatedWithFiveMinuteTtl(): void
    {
        $scenario = new Scenario();
        $hold = $scenario->holdService->hold($scenario->supplier(), $scenario->slotKey(), $scenario->now());

        self::assertNotSame('', $hold->holdToken);
        self::assertSame(300, $hold->secondsLeft($scenario->now()));
        self::assertSame(900, $hold->maxExpiresAt->getTimestamp() - $hold->createdAt->getTimestamp());
    }

    /** HOLD-01: на слот одночасно допускається рівно одна активна hold. */
    public function testSecondHoldOnSameSlotIsRejected(): void
    {
        $scenario = new Scenario();
        $scenario->holdService->hold($scenario->supplier(), $scenario->slotKey(), $scenario->now());

        try {
            $scenario->holdService->hold(
                $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
                $scenario->slotKey(),
                $scenario->now(),
            );
            self::fail('Другий холд на той самий слот мав бути відхилений');
        } catch (SlotHeldException $error) {
            self::assertSame('SLOT_HELD', $error->errorCode());
            self::assertSame(409, $error->httpStatus());
            self::assertStringContainsString('оформлює інший користувач', $error->getMessage());
        }
    }

    /** HOLD-02: активність користувача продовжує TTL знову до 5 хв. */
    public function testExtendRefreshesTtl(): void
    {
        $scenario = new Scenario();
        $key = $scenario->slotKey();
        $hold = $scenario->holdService->hold($scenario->supplier(), $key, $scenario->now());

        $later = $scenario->now()->modify('+4 minutes');
        $extended = $scenario->holdService->extend($scenario->supplier(), $key, $hold->holdToken, $later);

        self::assertSame(300, $extended->secondsLeft($later));
        self::assertSame($hold->holdToken, $extended->holdToken);
    }

    /** HOLD-02: сумарна тривалість однієї hold обмежена holdMaxMinutes (15 хв). */
    public function testExtendIsCappedByHoldMaxMinutes(): void
    {
        $scenario = new Scenario();
        $key = $scenario->slotKey();
        $hold = $scenario->holdService->hold($scenario->supplier(), $key, $scenario->now());

        // Клієнт шле heartbeat раз на 60 с; на 14-й хвилині залишок обрізається
        // стелею holdMaxMinutes і становить рівно 60 с.
        $extended = $this->heartbeat($scenario, $key, $hold->holdToken, 14);
        $atFourteen = $scenario->now()->modify('+14 minutes');

        self::assertSame(60, $extended->secondsLeft($atFourteen));
    }

    /** HOLD-02: після вичерпання ліміту холд знімається. */
    public function testExtendAfterMaxMinutesFails(): void
    {
        $scenario = new Scenario();
        $key = $scenario->slotKey();
        $hold = $scenario->holdService->hold($scenario->supplier(), $key, $scenario->now());

        $this->heartbeat($scenario, $key, $hold->holdToken, 14);

        try {
            $scenario->holdService->extend($scenario->supplier(), $key, $hold->holdToken, $scenario->now()->modify('+16 minutes'));
            self::fail('Холд після 15 хв мав бути знятий');
        } catch (HoldExpiredException $error) {
            self::assertSame('HOLD_EXPIRED', $error->errorCode());
            self::assertStringContainsString('Час оформлення вичерпано', $error->getMessage());
        }
    }

    /** HOLD-03: продовжити холд може лише його власник. */
    public function testExtendWithForeignTokenIsRejected(): void
    {
        $scenario = new Scenario();
        $key = $scenario->slotKey();
        $scenario->holdService->hold($scenario->supplier(), $key, $scenario->now());

        try {
            $scenario->holdService->extend($scenario->supplier(Scenario::OTHER_SUPPLIER_ID), $key, 'чужий-токен', $scenario->now());
            self::fail('Чужий токен не мав продовжити холд');
        } catch (HoldNotOwnedException $error) {
            self::assertSame('HOLD_NOT_OWNED', $error->errorCode());
            self::assertSame(403, $error->httpStatus());
        }
    }

    /** HOLD-03: явне скасування знімає холд негайно. */
    public function testReleaseAllowsAnotherUserToHoldSlot(): void
    {
        $scenario = new Scenario();
        $key = $scenario->slotKey();
        $hold = $scenario->holdService->hold($scenario->supplier(), $key, $scenario->now());

        $scenario->holdService->release($key, $hold->holdToken);
        $second = $scenario->holdService->hold($scenario->supplier(Scenario::OTHER_SUPPLIER_ID), $key, $scenario->now());

        self::assertNotSame($hold->holdToken, $second->holdToken);
    }

    /** HOLD-03: протухання TTL повертає слот у available без додаткових дій. */
    public function testExpiredHoldFreesSlotAutomatically(): void
    {
        $scenario = new Scenario();
        $key = $scenario->slotKey();
        $scenario->holdService->hold($scenario->supplier(), $key, $scenario->now());

        $afterTtl = $scenario->now()->modify('+6 minutes');

        self::assertNull($scenario->holds->get($key, $afterTtl));

        $second = $scenario->holdService->hold($scenario->supplier(Scenario::OTHER_SUPPLIER_ID), $key, $afterTtl);

        self::assertNotSame('', $second->holdToken);
    }

    /** Холд на вже заброньований слот неможливий. */
    public function testHoldOnBookedSlotIsRejected(): void
    {
        $scenario = new Scenario();
        $scenario->book();

        $this->expectException(SlotAlreadyBookedException::class);
        $scenario->holdService->hold(
            $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
            $scenario->slotKey(),
            $scenario->now(),
        );
    }

    public function testStoreStaffCannotHoldSlot(): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $scenario->holdService->hold($scenario->storeStaff(), $scenario->slotKey(), $scenario->now());
    }

    /** Активний холд робить слот `held` у сітці інших постачальників (GRID-01, крок 8). */
    public function testHeldSlotIsVisibleInGrid(): void
    {
        $scenario = new Scenario();
        $scenario->holdService->hold($scenario->supplier(), $scenario->slotKey(), $scenario->now());

        $grid = $scenario->grid->grid(
            Scenario::STORE_ID,
            '2026-08-28',
            $scenario->supplier(Scenario::OTHER_SUPPLIER_ID),
            $scenario->now(),
        );

        self::assertSame(1, $grid->countInState(\App\Domain\Slot\SlotState::Held));
    }

    /** Імітація heartbeat раз на хвилину протягом $minutes хвилин. */
    private function heartbeat(
        Scenario $scenario,
        \App\Domain\Slot\SlotKey $key,
        string $holdToken,
        int $minutes,
    ): \App\Domain\Hold\SlotHold {
        $hold = null;

        for ($minute = 1; $minute <= $minutes; ++$minute) {
            $hold = $scenario->holdService->extend(
                $scenario->supplier(),
                $key,
                $holdToken,
                $scenario->now()->modify(\sprintf('+%d minutes', $minute)),
            );
        }

        \assert(null !== $hold);

        return $hold;
    }
}
