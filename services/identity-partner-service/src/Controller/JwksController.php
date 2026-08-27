<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Jwt\RsaJwtCodec;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JWKS партнерського контуру (AUTH-64).
 *
 * Публічні ключі роздаються іншим сервісам, приватні ніколи не залишають
 * identity-сервіс. `kid` дозволяє ротацію з періодом перекриття.
 */
final readonly class JwksController
{
    public function __construct(private RsaJwtCodec $codec)
    {
    }

    #[Route('/.well-known/jwks.json', name: 'partner_jwks', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->codec->publicKeyPem()) ?: throw new \RuntimeException('Публічний ключ partner-контуру недоступний.'));

        if (false === $details || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('Не вдалося сформувати JWKS для partner-контуру.');
        }

        return new JsonResponse([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $this->codec->keyId(),
                'n' => self::base64Url((string) $details['rsa']['n']),
                'e' => self::base64Url((string) $details['rsa']['e']),
            ]],
        ]);
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
