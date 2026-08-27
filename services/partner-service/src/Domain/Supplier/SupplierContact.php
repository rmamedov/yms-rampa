<?php

declare(strict_types=1);

namespace App\Domain\Supplier;

use App\Domain\Shared\PhoneNormalizer;
use App\Domain\Shared\ValidationException;

/**
 * Контактна особа постачальника (SUP-01, розділ 10.4 `suppliers.contacts`).
 */
final readonly class SupplierContact
{
    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $email = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new ValidationException('Вкажіть ім\'я контактної особи.', 'SUPPLIER_CONTACT_NAME_REQUIRED');
        }

        if (mb_strlen($name, 'UTF-8') > 200) {
            throw new ValidationException(
                'Ім\'я контактної особи не може бути довшим за 200 символів.',
                'SUPPLIER_CONTACT_NAME_TOO_LONG',
            );
        }

        $phoneRaw = isset($data['phone']) ? (string) $data['phone'] : null;
        $emailRaw = isset($data['email']) ? trim((string) $data['email']) : null;

        if (null !== $emailRaw && '' !== $emailRaw && false === filter_var($emailRaw, \FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(
                \sprintf('Некоректна адреса e-mail «%s».', $emailRaw),
                'SUPPLIER_CONTACT_EMAIL_INVALID',
            );
        }

        return new self(
            name: $name,
            phone: PhoneNormalizer::normalizeOptional($phoneRaw),
            email: ('' === $emailRaw) ? null : $emailRaw,
        );
    }

    /**
     * @return array{name: string, phone: string|null, email: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }
}
