<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exception;

use App\Domain\Exception\ProblemException;

/**
 * BOOK-02: постачальник неактивний у partner-service або не має доступу
 * до цієї філії.
 */
final class SupplierNotAllowedException extends ProblemException
{
    public const string ERROR_CODE = 'SUPPLIER_NOT_ALLOWED';

    public function __construct(
        public readonly string $supplierId,
        public readonly string $storeId,
        string $message = 'Постачальник не має доступу до цієї філії',
    ) {
        parent::__construct($message);
    }

    public static function suspended(string $supplierId, string $storeId): self
    {
        return new self($supplierId, $storeId, 'Постачальник неактивний і не може створювати бронювання');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
