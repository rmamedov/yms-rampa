<?php

declare(strict_types=1);

namespace App\Infrastructure\Jwt;

/**
 * Ключова пара RS256 контуру (AUTH-02, AUTH-64).
 *
 * Приватний ключ ніколи не залишає identity-сервіс; публічний роздається
 * іншим сервісам через JWKS. `kid` потрапляє в заголовок токена і дозволяє
 * ротацію ключів з періодом перекриття.
 */
final readonly class JwtKeyPair
{
    public function __construct(
        public string $privateKeyPem,
        public string $publicKeyPem,
        public string $keyId,
    ) {
        if ('' === trim($privateKeyPem) || '' === trim($publicKeyPem)) {
            throw new \InvalidArgumentException('Ключова пара JWT не може бути порожньою.');
        }
    }

    /** Генерація ефемерної пари (dev-режим і тести). */
    public static function generate(string $keyId = 'partner-dev', int $bits = 2048): self
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);

        if (false === $resource) {
            throw new \RuntimeException('Не вдалося згенерувати ключову пару RSA: '.(openssl_error_string() ?: 'невідома помилка openssl'));
        }

        if (!openssl_pkey_export($resource, $privateKeyPem)) {
            throw new \RuntimeException('Не вдалося експортувати приватний ключ RSA.');
        }

        $details = openssl_pkey_get_details($resource);

        if (false === $details || !isset($details['key'])) {
            throw new \RuntimeException('Не вдалося отримати публічний ключ RSA.');
        }

        return new self($privateKeyPem, (string) $details['key'], $keyId);
    }

    public static function fromFiles(string $privateKeyPath, string $publicKeyPath, string $keyId): self
    {
        if (!is_readable($privateKeyPath) || !is_readable($publicKeyPath)) {
            throw new \RuntimeException(\sprintf('Ключі JWT недоступні для читання: %s / %s', $privateKeyPath, $publicKeyPath));
        }

        return new self(
            (string) file_get_contents($privateKeyPath),
            (string) file_get_contents($publicKeyPath),
            $keyId,
        );
    }
}
