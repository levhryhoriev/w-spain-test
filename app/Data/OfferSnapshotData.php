<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class OfferSnapshotData
{
    public function __construct(
        public string $externalId,
        public string $propertyCode,
        public string $propertyName,
        public string $propertyCity,
        public CarbonImmutable $checkIn,
        public CarbonImmutable $checkOut,
        public int $maxGuests,
        public int $price,
        public string $currency,
        public int $availableUnits,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $sourceSentAt,
    ) {
    }

    public static function fromPayload(mixed $payload, CarbonImmutable $sourceSentAt): self
    {
        return new self(
            externalId: self::stringValue($payload, 'external_id'),
            propertyCode: self::nestedStringValue($payload, 'property', 'code'),
            propertyName: self::nestedStringValue($payload, 'property', 'name'),
            propertyCity: self::nestedStringValue($payload, 'property', 'city'),
            checkIn: self::dateValue($payload, 'check_in'),
            checkOut: self::dateValue($payload, 'check_out'),
            maxGuests: self::integerValue($payload, 'max_guests'),
            price: self::integerValue($payload, 'price'),
            currency: self::stringValue($payload, 'currency'),
            availableUnits: self::integerValue($payload, 'available_units'),
            expiresAt: self::dateValue($payload, 'expires_at'),
            sourceSentAt: $sourceSentAt,
        );
    }

    private static function stringValue(mixed $payload, string $key): string
    {
        if (! is_array($payload) || ! isset($payload[$key]) || ! is_string($payload[$key])) {
            throw new InvalidArgumentException('The persisted offer payload is invalid.');
        }

        return $payload[$key];
    }

    private static function nestedStringValue(mixed $payload, string $parent, string $key): string
    {
        if (! is_array($payload)) {
            throw new InvalidArgumentException('The persisted offer payload is invalid.');
        }

        return self::stringValue($payload[$parent] ?? null, $key);
    }

    private static function integerValue(mixed $payload, string $key): int
    {
        if (! is_array($payload) || ! isset($payload[$key]) || ! is_int($payload[$key])) {
            throw new InvalidArgumentException('The persisted offer payload is invalid.');
        }

        return $payload[$key];
    }

    private static function dateValue(mixed $payload, string $key): CarbonImmutable
    {
        return CarbonImmutable::parse(self::stringValue($payload, $key))->utc();
    }
}
