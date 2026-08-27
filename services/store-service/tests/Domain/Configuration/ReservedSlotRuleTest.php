<?php

declare(strict_types=1);

namespace App\Tests\Domain\Configuration;

use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Правила резервування слотів: STC-40, STC-42, DATA-33.
 */
#[CoversClass(ReservedSlotRule::class)]
final class ReservedSlotRuleTest extends TestCase
{
    /** DATA-33: рівно одне з dayOfWeek / date (XOR). */
    public function testBothDayOfWeekAndDateAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('рівно одне з полів');

        $this->rule(dayOfWeek: 1, date: '2026-09-01');
    }

    public function testNeitherDayOfWeekNorDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('рівно одне з полів');

        $this->rule(dayOfWeek: null, date: null);
    }

    public function testWeeklyRuleIsAccepted(): void
    {
        $rule = $this->rule(dayOfWeek: 3);

        self::assertTrue($rule->isWeekly());
        self::assertSame(3, $rule->effectiveDayOfWeek());
    }

    /** Разовий резерв: день тижня обчислюється з дати (2026-09-01 — вівторок). */
    public function testDatedRuleDerivesDayOfWeekFromDate(): void
    {
        $rule = $this->rule(dayOfWeek: null, date: '2026-09-01');

        self::assertFalse($rule->isWeekly());
        self::assertSame(2, $rule->effectiveDayOfWeek());
    }

    /** STC-40: rampId обовʼязковий — бронювання завжди привʼязане до рампи. */
    public function testRampIdIsMandatory(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Рампа для правила резерву обовʼязкова');

        $this->rule(rampId: '');
    }

    public function testSupplierIdIsMandatory(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Постачальник');

        $this->rule(supplierId: '  ');
    }

    public function testSlotStartTimeMustFollowFiveMinuteStep(): void
    {
        $this->expectException(ValidationException::class);

        $this->rule(slotStartTime: '08:07');
    }

    public function testValidToMustBeAfterValidFrom(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('пізнішим за початок');

        $this->rule(
            validFrom: new \DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            validTo: new \DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    public function testOpenEndedValidityIsAllowed(): void
    {
        $rule = $this->rule(validTo: null);

        self::assertTrue($rule->isValidAt(new \DateTimeImmutable('2030-01-01T00:00:00+00:00')));
    }

    public function testInactiveRuleIsNeverValid(): void
    {
        $rule = $this->rule(active: false);

        self::assertFalse($rule->isValidAt(new \DateTimeImmutable('2026-09-01T00:00:00+00:00')));
    }

    /** STC-42: перетин двох правил на один слот заборонений. */
    public function testTwoWeeklyRulesOnSameSlotConflict(): void
    {
        $first = $this->rule(dayOfWeek: 1, slotStartTime: '08:00', rampId: 'r1');
        $second = $this->rule(dayOfWeek: 1, slotStartTime: '08:00', rampId: 'r1', supplierId: 'supplier-2');

        self::assertTrue($first->conflictsWith($second));
    }

    public function testRulesOnDifferentRampsDoNotConflict(): void
    {
        $first = $this->rule(dayOfWeek: 1, slotStartTime: '08:00', rampId: 'r1');
        $second = $this->rule(dayOfWeek: 1, slotStartTime: '08:00', rampId: 'r2');

        self::assertFalse($first->conflictsWith($second));
    }

    public function testRulesAtDifferentTimesDoNotConflict(): void
    {
        $first = $this->rule(dayOfWeek: 1, slotStartTime: '08:00');
        $second = $this->rule(dayOfWeek: 1, slotStartTime: '08:30');

        self::assertFalse($first->conflictsWith($second));
    }

    public function testRulesWithDisjointValidityDoNotConflict(): void
    {
        $first = $this->rule(
            dayOfWeek: 1,
            validFrom: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            validTo: new \DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        );
        $second = $this->rule(
            dayOfWeek: 1,
            validFrom: new \DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            validTo: null,
        );

        self::assertFalse($first->conflictsWith($second));
    }

    /** Щотижневе правило перекриває разовий резерв того самого дня тижня. */
    public function testWeeklyRuleConflictsWithDatedRuleOnSameWeekday(): void
    {
        $weekly = $this->rule(dayOfWeek: 2, slotStartTime: '08:00');
        $dated = $this->rule(dayOfWeek: null, date: '2026-09-01', slotStartTime: '08:00');

        self::assertTrue($weekly->conflictsWith($dated));
    }

    public function testTwoDatedRulesOnDifferentDatesDoNotConflict(): void
    {
        $first = $this->rule(dayOfWeek: null, date: '2026-09-01', slotStartTime: '08:00');
        $second = $this->rule(dayOfWeek: null, date: '2026-09-08', slotStartTime: '08:00');

        self::assertFalse($first->conflictsWith($second));
    }

    public function testRuleDoesNotConflictWithItself(): void
    {
        $rule = $this->rule(dayOfWeek: 1);

        self::assertFalse($rule->conflictsWith($rule));
    }

    private function rule(
        ?string $id = null,
        string $storeId = BranchFactory::KYIV_ID,
        string $supplierId = 'supplier-1',
        string $rampId = 'r1',
        string $slotStartTime = '08:00',
        ?int $dayOfWeek = 1,
        ?string $date = null,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validTo = null,
        bool $active = true,
    ): ReservedSlotRule {
        return new ReservedSlotRule(
            id: $id ?? Uuid::v4(),
            storeId: $storeId,
            supplierId: $supplierId,
            rampId: $rampId,
            slotStartTime: $slotStartTime,
            dayOfWeek: $dayOfWeek,
            date: $date,
            validFrom: $validFrom ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            validTo: $validTo,
            active: $active,
        );
    }
}
