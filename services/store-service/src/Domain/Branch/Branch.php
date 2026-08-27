<?php

declare(strict_types=1);

namespace App\Domain\Branch;

use App\Domain\Configuration\ConfigurationReadiness;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\ValidationException;

/**
 * Агрегат «філія магазину» (10.2.1).
 *
 * Складається з read-only блоку MCP (INT-03) і YMS-полів, які редагуються
 * виключно в адмін-панелі. Синхронізація ніколи не змінює YMS-поля (INT-08).
 */
final class Branch
{
    public const int MISSING_SYNC_THRESHOLD = 3;
    public const int DISPLAY_NAME_MAX = 120;
    public const int ADDRESS_OVERRIDE_MAX = 200;
    public const int SCHEMA_VERSION = 2;

    /** @var list<IneligibilityReason> */
    private array $ineligibilityReasons;

    /**
     * @param list<IneligibilityReason> $ineligibilityReasons
     */
    private function __construct(
        private McpData $mcpData,
        private \DateTimeImmutable $syncedAt,
        private YmsStatus $ymsStatus,
        private bool $visibleToSuppliers,
        private int $missingSyncCount,
        array $ineligibilityReasons,
        private ?string $displayName,
        private ?string $phone,
        private ?string $addressOverride,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private ?\DateTimeImmutable $archivedAt,
    ) {
        $this->ineligibilityReasons = $ineligibilityReasons;
    }

    /**
     * Нова філія, створена синхронізацією MCP: завжди not_configured і невидима
     * постачальникам, доки її не налаштують і не активують (INT-07).
     */
    public static function createFromMcp(McpData $data, \DateTimeImmutable $syncedAt): self
    {
        return new self(
            mcpData: $data,
            syncedAt: $syncedAt,
            ymsStatus: YmsStatus::NotConfigured,
            visibleToSuppliers: false,
            missingSyncCount: 0,
            ineligibilityReasons: BranchEligibility::evaluate($data),
            displayName: null,
            phone: null,
            addressOverride: null,
            createdAt: $syncedAt,
            updatedAt: $syncedAt,
            archivedAt: null,
        );
    }

    /**
     * Відновлення агрегата зі сховища.
     *
     * @param list<IneligibilityReason> $ineligibilityReasons
     */
    public static function restore(
        McpData $mcpData,
        \DateTimeImmutable $syncedAt,
        YmsStatus $ymsStatus,
        bool $visibleToSuppliers,
        int $missingSyncCount,
        array $ineligibilityReasons,
        ?string $displayName,
        ?string $phone,
        ?string $addressOverride,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $archivedAt,
    ): self {
        return new self(
            $mcpData,
            $syncedAt,
            $ymsStatus,
            $visibleToSuppliers,
            max(0, $missingSyncCount),
            $ineligibilityReasons,
            $displayName,
            $phone,
            $addressOverride,
            $createdAt,
            $updatedAt,
            $archivedAt,
        );
    }

    public function id(): string
    {
        return $this->mcpData->branchId;
    }

    public function mcpData(): McpData
    {
        return $this->mcpData;
    }

    public function externalId(): string
    {
        return $this->mcpData->externalId;
    }

    public function city(): string
    {
        return $this->mcpData->city;
    }

    public function ymsStatus(): YmsStatus
    {
        return $this->ymsStatus;
    }

    public function visibleToSuppliers(): bool
    {
        return $this->visibleToSuppliers;
    }

    public function missingSyncCount(): int
    {
        return $this->missingSyncCount;
    }

    public function syncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function addressOverride(): ?string
    {
        return $this->addressOverride;
    }

    /** @return list<IneligibilityReason> */
    public function ineligibilityReasons(): array
    {
        return $this->ineligibilityReasons;
    }

    public function isEligible(): bool
    {
        return [] === $this->ineligibilityReasons;
    }

    /** STC-02: за замовчуванням назва для відображення — адреса з MCP. */
    public function effectiveDisplayName(): string
    {
        return $this->displayName ?? $this->mcpData->address;
    }

    /** STC-07: заповнений addressOverride показується замість address з MCP. */
    public function effectiveAddress(): string
    {
        return $this->addressOverride ?? $this->mcpData->address;
    }

    /**
     * INT-08: синхронізація оновлює лише блок mcpData і не змінює жодного YMS-поля
     * (вікна прийому, розмір слоту, рампи, ліміти, резерви, блокування, ymsStatus).
     *
     * @return array<string, array{old: mixed, new: mixed}> перелік змінених MCP-полів
     */
    public function applyMcpUpdate(McpData $fresh, \DateTimeImmutable $syncedAt): array
    {
        if ($fresh->branchId !== $this->mcpData->branchId) {
            throw ValidationException::field('branchId', 'Зіставлення філій виконується виключно за branchId');
        }

        $changes = $this->mcpData->diff($fresh);

        $this->mcpData = $fresh;
        $this->ineligibilityReasons = BranchEligibility::evaluate($fresh);
        $this->syncedAt = $syncedAt;
        $this->missingSyncCount = 0;

        if ([] !== $changes) {
            $this->updatedAt = $syncedAt;
        }

        // Філія, що стала непридатною, більше не може показуватись постачальникам.
        if (!$this->isEligible() && $this->visibleToSuppliers) {
            $this->visibleToSuppliers = false;
        }

        return $changes;
    }

    /** Поява філії у вибірці MCP скидає лічильник відсутностей (INT-09). */
    public function markPresentInSync(\DateTimeImmutable $syncedAt): void
    {
        $this->syncedAt = $syncedAt;
        $this->missingSyncCount = 0;
    }

    /**
     * INT-09 / SYNC-03: філія, відсутня у повній вибірці MCP, позначається «пропалою».
     *
     * @return bool чи досягнуто порогу 3 послідовних відсутностей і філію треба архівувати
     */
    public function markMissingInSync(\DateTimeImmutable $at): bool
    {
        ++$this->missingSyncCount;
        $this->updatedAt = $at;

        return $this->missingSyncCount >= self::MISSING_SYNC_THRESHOLD
            && YmsStatus::Archived !== $this->ymsStatus;
    }

    /** DATA-07: архівація ніколи не видаляє документ фізично. */
    public function archiveBySync(\DateTimeImmutable $at): void
    {
        $this->ymsStatus = YmsStatus::Archived;
        $this->visibleToSuppliers = false;
        $this->archivedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Зміна YMS-статусу вручну (STC-03, STC-05, STC-06).
     *
     * @throws ValidationException якщо магазин не «налаштований» за STL-04
     */
    public function changeStatus(YmsStatus $target, ConfigurationReadiness $readiness, \DateTimeImmutable $at): void
    {
        if ($target === $this->ymsStatus) {
            return;
        }

        if (!$this->ymsStatus->canTransitionTo($target)) {
            throw new ConflictException(
                \sprintf(
                    'Перехід зі статусу «%s» у «%s» неможливий',
                    $this->ymsStatus->label(),
                    $target->label(),
                ),
                'INVALID_STATUS_TRANSITION',
            );
        }

        if (YmsStatus::Active === $target) {
            $this->assertActivatable($readiness);
        }

        $this->ymsStatus = $target;
        $this->updatedAt = $at;

        if (YmsStatus::Archived === $target) {
            $this->archivedAt = $at;
        }

        // Видимість постачальникам можлива лише в статусі active (STC-04, DATA-08).
        if (!$target->allowsSupplierVisibility()) {
            $this->visibleToSuppliers = false;
        }
    }

    /**
     * DATA-08: visibleToSuppliers=true можливе лише при ymsStatus=active.
     * Порушення інваріанта при записі → 409 STORE_NOT_CONFIGURED.
     */
    public function setVisibleToSuppliers(bool $visible, \DateTimeImmutable $at): void
    {
        if ($visible && !$this->ymsStatus->allowsSupplierVisibility()) {
            throw ConflictException::storeNotConfigured(
                'Зробити магазин видимим постачальникам можна лише у статусі «Активний»',
            );
        }

        if ($visible && !$this->isEligible()) {
            throw ConflictException::storeNotConfigured(
                'Магазин непридатний до роботи за даними MCP: '.$this->ineligibilityText(),
            );
        }

        $this->visibleToSuppliers = $visible;
        $this->updatedAt = $at;
    }

    /** STC-02: назва для відображення 1–120 символів. */
    public function rename(?string $displayName, \DateTimeImmutable $at): void
    {
        if (null !== $displayName) {
            $displayName = trim($displayName);

            if ('' === $displayName) {
                $displayName = null;
            } elseif (mb_strlen($displayName) > self::DISPLAY_NAME_MAX) {
                throw ValidationException::field(
                    'displayName',
                    \sprintf('Назва для відображення не може перевищувати %d символів', self::DISPLAY_NAME_MAX),
                );
            }
        }

        $this->displayName = $displayName;
        $this->updatedAt = $at;
    }

    /** STC-02: телефон у форматі +380XXXXXXXXX; поле може бути порожнім. */
    public function setPhone(?string $phone, \DateTimeImmutable $at): void
    {
        if (null !== $phone) {
            $phone = trim($phone);

            if ('' === $phone) {
                $phone = null;
            } elseif (1 !== preg_match('/^\+380\d{9}$/', $phone)) {
                throw ValidationException::field('phone', 'Телефон має бути у форматі +380XXXXXXXXX');
            }
        }

        $this->phone = $phone;
        $this->updatedAt = $at;
    }

    /** STC-07: addressOverride nullable, до 200 символів; координати завжди з MCP. */
    public function setAddressOverride(?string $addressOverride, \DateTimeImmutable $at): void
    {
        if (null !== $addressOverride) {
            $addressOverride = trim($addressOverride);

            if ('' === $addressOverride) {
                $addressOverride = null;
            } elseif (mb_strlen($addressOverride) > self::ADDRESS_OVERRIDE_MAX) {
                throw ValidationException::field(
                    'addressOverride',
                    \sprintf('Адреса для відображення не може перевищувати %d символів', self::ADDRESS_OVERRIDE_MAX),
                );
            }
        }

        $this->addressOverride = $addressOverride;
        $this->updatedAt = $at;
    }

    public function ineligibilityText(): string
    {
        return implode('; ', array_map(
            static fn (IneligibilityReason $r): string => $r->message(),
            $this->ineligibilityReasons,
        ));
    }

    /**
     * STC-03: переведення у active заблоковане, доки магазин не «налаштований» за STL-04.
     */
    private function assertActivatable(ConfigurationReadiness $readiness): void
    {
        if (!$this->isEligible()) {
            throw new ValidationException(
                'Неможливо активувати: '.$this->ineligibilityText(),
                'STORE_NOT_ELIGIBLE',
                ['ymsStatus' => $this->ineligibilityText()],
            );
        }

        if (!$readiness->complete) {
            throw new ValidationException(
                \sprintf(
                    'Неможливо активувати: не завершено налаштування магазину. Відсутні параметри: %s',
                    $readiness->missingAsText(),
                ),
                'STORE_NOT_CONFIGURED',
                ['ymsStatus' => 'Не завершено налаштування магазину: '.$readiness->missingAsText()],
            );
        }
    }
}
