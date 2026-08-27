<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Перетворення дат між BSON і PHP.
 *
 * DATA-01: у базі — BSON `date` в UTC; у домені — DateTimeImmutable в UTC.
 */
final class MongoCodec
{
    /** @var array<string, string> */
    public const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public static function toBson(?\DateTimeImmutable $date): ?\MongoDB\BSON\UTCDateTime
    {
        return null === $date ? null : new \MongoDB\BSON\UTCDateTime($date);
    }

    public static function toPhp(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return \DateTimeImmutable::createFromMutable($value->toDateTime())
                ->setTimezone(new \DateTimeZone('UTC'));
        }

        if (\is_string($value) && '' !== $value) {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return null;
    }

    public static function toPhpRequired(mixed $value, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        return self::toPhp($value) ?? $fallback;
    }
}
