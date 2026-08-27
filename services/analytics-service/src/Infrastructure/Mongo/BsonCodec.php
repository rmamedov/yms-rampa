<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Перетворення дат між доменом (\DateTimeImmutable у UTC) та BSON
 * (MongoDB\BSON\UTCDateTime). Кодування вимагає ext-mongodb, декодування —
 * ні: воно розуміє і UTCDateTime, і ISO-8601 рядок, тому мапери
 * тестуються без розширення.
 */
final readonly class BsonCodec
{
    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function encode(array $document): array
    {
        if (!MongoConnection::isDriverAvailable()) {
            throw new \RuntimeException('Розширення PHP ext-mongodb не встановлено: неможливо сформувати BSON-документ.');
        }

        /** @var class-string $utcDateTimeClass */
        $utcDateTimeClass = 'MongoDB\BSON\UTCDateTime';

        foreach ($document as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $document[$key] = new $utcDateTimeClass((int) $value->format('Uv'));
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $document[$key] = self::encode($value);
            }
        }

        return $document;
    }

    public static function decodeDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }

        if (is_object($value) && method_exists($value, 'toDateTime')) {
            /** @var \DateTimeInterface $date */
            $date = $value->toDateTime();

            return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    public static function requireDate(mixed $value, string $field): \DateTimeImmutable
    {
        $date = self::decodeDate($value);
        if ($date === null) {
            throw new \RuntimeException(sprintf('Документ read-моделі містить некоректне поле дати «%s».', $field));
        }

        return $date;
    }
}
