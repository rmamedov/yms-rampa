<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Витягує ідентифікатор постачальника поточного запиту.
 *
 * У проді автентифікацію виконує api-gateway разом з identity-partner-service
 * (SUP-AUTH-01): токен partner-контуру валідується там, а вниз до сервісу
 * передаються заголовки `X-Supplier-Id` і `X-Partner-Role` (клейм `role`,
 * однина — рівно одна роль на користувача). Сам partner-service токени
 * не розбирає.
 */
final class SupplierContext
{
    public const HEADER_SUPPLIER_ID = 'X-Supplier-Id';
    public const HEADER_ROLE = 'X-Partner-Role';

    public function supplierId(Request $request): string
    {
        $supplierId = $request->headers->get(self::HEADER_SUPPLIER_ID);

        if (!\is_string($supplierId) || '' === trim($supplierId)) {
            throw new ValidationException(
                'Не визначено постачальника запиту (відсутній заголовок X-Supplier-Id від api-gateway).',
                'SUPPLIER_CONTEXT_MISSING',
            );
        }

        return trim($supplierId);
    }

    public function role(Request $request): ?string
    {
        $role = $request->headers->get(self::HEADER_ROLE);

        return \is_string($role) && '' !== trim($role) ? trim($role) : null;
    }
}
