<?php

declare(strict_types=1);

namespace App\Infrastructure\Jwt;

/**
 * Постачальник ключової пари partner-контуру.
 *
 * У проді ключі приходять із секретів Kubernetes (AUTH-05, AUTH-64). Якщо
 * файлів немає (локальна розробка), пара генерується один раз і кешується у
 * `var/jwt/` — щоб сервіс піднімався «з коробки», але прод-ключі при цьому
 * ніколи не підмінялись мовчки: за наявності шляхів у конфігурації
 * використовуються саме вони.
 */
final class JwtKeyPairProvider
{
    private ?JwtKeyPair $keyPair = null;

    public function __construct(
        private readonly string $privateKeyPath,
        private readonly string $publicKeyPath,
        private readonly string $keyId,
        private readonly bool $allowGeneratedDevKeys = true,
    ) {
    }

    public function keyPair(): JwtKeyPair
    {
        if (null !== $this->keyPair) {
            return $this->keyPair;
        }

        if (is_readable($this->privateKeyPath) && is_readable($this->publicKeyPath)) {
            return $this->keyPair = JwtKeyPair::fromFiles($this->privateKeyPath, $this->publicKeyPath, $this->keyId);
        }

        if (!$this->allowGeneratedDevKeys) {
            throw new \RuntimeException(\sprintf(
                'Ключі JWT partner-контуру не знайдено (%s, %s), а генерація dev-ключів вимкнена.',
                $this->privateKeyPath,
                $this->publicKeyPath,
            ));
        }

        $pair = JwtKeyPair::generate($this->keyId);
        $this->persistDevKeys($pair);

        return $this->keyPair = $pair;
    }

    private function persistDevKeys(JwtKeyPair $pair): void
    {
        $directory = \dirname($this->privateKeyPath);

        if (!is_dir($directory) && !@mkdir($directory, 0o770, true) && !is_dir($directory)) {
            return;
        }

        @file_put_contents($this->privateKeyPath, $pair->privateKeyPem);
        @chmod($this->privateKeyPath, 0o600);
        @file_put_contents($this->publicKeyPath, $pair->publicKeyPem);
    }
}
