<?php

declare(strict_types=1);

namespace App\Tests\Domain\Configuration;

use App\Domain\Configuration\SlotBlock;
use App\Domain\Shared\Uuid;
use App\Domain\Shared\ValidationException;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Разові блокування слотів: STC-50, STC-51, STC-52.
 */
#[CoversClass(SlotBlock::class)]
final class SlotBlockTest extends TestCase
{
    /** STC-50: причина обовʼязкова, до 200 символів. */
    public function testReasonIsMandatory(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Причина блокування обовʼязкова');

        $this->block(reason: '   ');
    }

    public function testReasonLongerThan200CharsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('200');

        $this->block(reason: str_repeat('я', 201));
    }

    public function testEndMustBeAfterStart(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('пізнішим за початок');

        $this->block(
            from: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            to: new \DateTimeImmutable('2026-09-01T08:00:00+00:00'),
        );
    }

    /** STC-50: блокування без переліку рамп поширюється на всі рампи магазину. */
    public function testEmptyRampListCoversAllRamps(): void
    {
        $block = $this->block(rampIds: []);

        self::assertTrue($block->coversAllRamps());
        self::assertTrue($block->coversRamp('r1'));
        self::assertTrue($block->coversRamp('r9'));
    }

    public function testExplicitRampListLimitsCoverage(): void
    {
        $block = $this->block(rampIds: ['r1', 'r2']);

        self::assertFalse($block->coversAllRamps());
        self::assertTrue($block->coversRamp('r2'));
        self::assertFalse($block->coversRamp('r3'));
    }

    public function testDuplicateAndEmptyRampIdsAreNormalised(): void
    {
        $block = $this->block(rampIds: ['r1', 'r1', ' ', 'r2']);

        self::assertSame(['r1', 'r2'], $block->rampIds);
    }

    /** STC-51: блокування перетинається з діапазоном видачі слотів. */
    public function testOverlapDetection(): void
    {
        $block = $this->block(
            from: new \DateTimeImmutable('2026-09-01T08:00:00+00:00'),
            to: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );

        self::assertTrue($block->overlaps(
            new \DateTimeImmutable('2026-09-01T11:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T14:00:00+00:00'),
        ));
        self::assertFalse($block->overlaps(
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T14:00:00+00:00'),
        ));
        self::assertFalse($block->overlaps(
            new \DateTimeImmutable('2026-09-02T08:00:00+00:00'),
            new \DateTimeImmutable('2026-09-02T12:00:00+00:00'),
        ));
    }

    /** STC-52: дострокове зняття блокування. */
    public function testReleaseProducesReleasedCopyAndIsIdempotent(): void
    {
        $block = $this->block();
        $releasedAt = new \DateTimeImmutable('2026-08-31T10:00:00+00:00');

        $released = $block->release($releasedAt);

        self::assertFalse($block->isReleased(), 'оригінал незмінний');
        self::assertTrue($released->isReleased());
        self::assertSame($releasedAt, $released->releasedAt);
        self::assertSame($released, $released->release(new \DateTimeImmutable('2026-09-01T00:00:00+00:00')));
    }

    public function testReleasedBlockNoLongerOverlaps(): void
    {
        $block = $this->block()->release(new \DateTimeImmutable('2026-08-31T10:00:00+00:00'));

        self::assertFalse($block->overlaps(
            new \DateTimeImmutable('2026-09-01T08:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        ));
    }

    /**
     * @param list<string> $rampIds
     */
    private function block(
        array $rampIds = ['r1'],
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        string $reason = 'Ремонт рампи',
    ): SlotBlock {
        return new SlotBlock(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            rampIds: $rampIds,
            blockFrom: $from ?? new \DateTimeImmutable('2026-09-01T08:00:00+00:00'),
            blockTo: $to ?? new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            reason: $reason,
        );
    }
}
