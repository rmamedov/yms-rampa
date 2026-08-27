<?php

declare(strict_types=1);

namespace App\Tests\Domain\Branch;

use App\Domain\Branch\Branch;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\ConfigurationReadiness;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\ValidationException;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Правила агрегата філії: STC-01..STC-07, STL-04, DATA-08, INT-07, INT-08.
 */
#[CoversClass(Branch::class)]
final class BranchTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-27T08:00:00+00:00');
    }

    /** INT-07: нова філія — not_configured і невидима постачальникам. */
    public function testNewBranchIsNotConfiguredAndInvisible(): void
    {
        $branch = BranchFactory::branch();

        self::assertSame(YmsStatus::NotConfigured, $branch->ymsStatus());
        self::assertFalse($branch->visibleToSuppliers());
        self::assertSame(0, $branch->missingSyncCount());
    }

    /** STC-03: активація заблокована, доки магазин не «налаштований» за STL-04. */
    public function testActivationIsBlockedWhenStoreIsNotConfigured(): void
    {
        $branch = BranchFactory::branch();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Неможливо активувати: не завершено налаштування магазину');

        $branch->changeStatus(YmsStatus::Active, ConfigurationReadiness::absent(), $this->now);
    }

    public function testActivationErrorListsMissingSettings(): void
    {
        $branch = BranchFactory::branch();
        $readiness = BranchFactory::incompleteConfiguration()->readiness();

        try {
            $branch->changeStatus(YmsStatus::Active, $readiness, $this->now);
            self::fail('Очікувалась ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('STORE_NOT_CONFIGURED', $e->errorCode());
            self::assertStringContainsString('вікна прийому', $e->getMessage());
            self::assertStringContainsString('активні рампи', $e->getMessage());
            self::assertSame(422, $e->httpStatus());
        }
    }

    public function testActivationSucceedsWithCompleteConfiguration(): void
    {
        $branch = BranchFactory::branch();

        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $this->now);

        self::assertSame(YmsStatus::Active, $branch->ymsStatus());
    }

    /** Записи, відсіяні правилами фікстури, активувати не можна взагалі. */
    public function testIneligibleBranchCannotBeActivatedEvenWhenConfigured(): void
    {
        $branch = BranchFactory::branch(['externalId' => 'delete_filia']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Філію видалено в MCP');

        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $this->now);
    }

    /** DATA-08: visibleToSuppliers=true можливе лише при ymsStatus=active. */
    public function testVisibilityRequiresActiveStatus(): void
    {
        $branch = BranchFactory::branch();

        try {
            $branch->setVisibleToSuppliers(true, $this->now);
            self::fail('Очікувалась ConflictException');
        } catch (ConflictException $e) {
            self::assertSame('STORE_NOT_CONFIGURED', $e->errorCode());
            self::assertSame(409, $e->httpStatus());
        }
    }

    /** STC-05, STC-06: пауза знімає видимість постачальникам, статус не термінальний. */
    public function testPausingHidesStoreFromSuppliers(): void
    {
        $branch = $this->activeVisibleBranch();

        $branch->changeStatus(YmsStatus::Paused, BranchFactory::completeConfiguration()->readiness(), $this->now);

        self::assertSame(YmsStatus::Paused, $branch->ymsStatus());
        self::assertFalse($branch->visibleToSuppliers());
    }

    public function testPausedStoreCanReturnToActive(): void
    {
        $branch = $this->activeVisibleBranch();
        $readiness = BranchFactory::completeConfiguration()->readiness();

        $branch->changeStatus(YmsStatus::Paused, $readiness, $this->now);
        $branch->changeStatus(YmsStatus::Active, $readiness, $this->now);

        self::assertSame(YmsStatus::Active, $branch->ymsStatus());
        // Видимість не відновлюється автоматично — її вмикає адміністратор.
        self::assertFalse($branch->visibleToSuppliers());
    }

    public function testArchivedStatusIsTerminal(): void
    {
        $branch = BranchFactory::branch();
        $branch->changeStatus(YmsStatus::Archived, ConfigurationReadiness::absent(), $this->now);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('неможливий');

        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $this->now);
    }

    /** STC-07: addressOverride показується замість адреси MCP; координати не змінюються. */
    public function testAddressOverrideReplacesMcpAddressButNotCoordinates(): void
    {
        $branch = BranchFactory::branch();
        $originalLat = $branch->mcpData()->location?->latitude;

        $branch->setAddressOverride('вул. Хрещатик, 12 (вʼїзд з двору)', $this->now);

        self::assertSame('вул. Хрещатик, 12 (вʼїзд з двору)', $branch->effectiveAddress());
        self::assertSame('просп. Володимира Івасюка, 46', $branch->mcpData()->address);
        self::assertSame($originalLat, $branch->mcpData()->location?->latitude);
    }

    public function testEmptyAddressOverrideFallsBackToMcpAddress(): void
    {
        $branch = BranchFactory::branch();
        $branch->setAddressOverride('  ', $this->now);

        self::assertNull($branch->addressOverride());
        self::assertSame('просп. Володимира Івасюка, 46', $branch->effectiveAddress());
    }

    /** STC-02: за замовчуванням назва для відображення — адреса з MCP. */
    public function testDisplayNameDefaultsToMcpAddress(): void
    {
        $branch = BranchFactory::branch();

        self::assertSame('просп. Володимира Івасюка, 46', $branch->effectiveDisplayName());

        $branch->rename('Сільпо Івасюка', $this->now);

        self::assertSame('Сільпо Івасюка', $branch->effectiveDisplayName());
    }

    public function testDisplayNameLongerThan120CharsIsRejected(): void
    {
        $branch = BranchFactory::branch();

        $this->expectException(ValidationException::class);

        $branch->rename(str_repeat('я', 121), $this->now);
    }

    /** STC-02: телефон у форматі +380XXXXXXXXX або порожній. */
    #[DataProvider('phoneProvider')]
    public function testPhoneValidation(?string $phone, bool $valid): void
    {
        $branch = BranchFactory::branch();

        if (!$valid) {
            $this->expectException(ValidationException::class);
        }

        $branch->setPhone($phone, $this->now);

        if ($valid) {
            self::assertSame(null === $phone || '' === trim($phone) ? null : $phone, $branch->phone());
        }
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function phoneProvider(): iterable
    {
        yield 'валідний київський' => ['+380441234567', true];
        yield 'порожній дозволений' => [null, true];
        yield 'порожній рядок → null' => ['', true];
        yield 'без плюса' => ['380441234567', false];
        yield 'замало цифр' => ['+38044123456', false];
        yield 'забагато цифр' => ['+3804412345678', false];
        yield 'інша країна' => ['+48221234567', false];
    }

    /** INT-08: синхронізація оновлює лише блок MCP і не чіпає YMS-поля. */
    public function testMcpUpdateDoesNotTouchYmsFields(): void
    {
        $branch = $this->activeVisibleBranch();
        $branch->rename('Сільпо Івасюка', $this->now);
        $branch->setPhone('+380441234567', $this->now);

        $changes = $branch->applyMcpUpdate(
            BranchFactory::mcpData(['address' => 'просп. Володимира Івасюка, 48']),
            $this->now,
        );

        self::assertArrayHasKey('address', $changes);
        self::assertSame('просп. Володимира Івасюка, 48', $branch->mcpData()->address);
        self::assertSame('Сільпо Івасюка', $branch->displayName());
        self::assertSame('+380441234567', $branch->phone());
        self::assertSame(YmsStatus::Active, $branch->ymsStatus());
        self::assertTrue($branch->visibleToSuppliers());
    }

    public function testMcpUpdateWithoutChangesReportsEmptyDiff(): void
    {
        $branch = BranchFactory::branch();

        self::assertSame([], $branch->applyMcpUpdate(BranchFactory::mcpData(), $this->now));
    }

    /** INT-06: зіставлення виключно за branchId. */
    public function testMcpUpdateWithForeignBranchIdIsRejected(): void
    {
        $branch = BranchFactory::branch();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('branchId');

        $branch->applyMcpUpdate(
            BranchFactory::mcpData(['branchId' => '1eda8887-bf7c-6f38-b0cb-9503162b5586']),
            $this->now,
        );
    }

    /** Філія, що стала непридатною за даними MCP, автоматично зникає з supplier-web. */
    public function testBranchBecomingIneligibleLosesSupplierVisibility(): void
    {
        $branch = $this->activeVisibleBranch();

        $branch->applyMcpUpdate(BranchFactory::mcpData(['city' => '']), $this->now);

        self::assertFalse($branch->isEligible());
        self::assertFalse($branch->visibleToSuppliers());
    }

    private function activeVisibleBranch(): Branch
    {
        $branch = BranchFactory::branch();
        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $this->now);
        $branch->setVisibleToSuppliers(true, $this->now);

        return $branch;
    }
}
