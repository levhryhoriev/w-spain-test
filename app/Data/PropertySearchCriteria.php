<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PropertySearchCriteria
{
    private function __construct(
        public CarbonImmutable $checkIn,
        public CarbonImmutable $checkOut,
        public int $guests,
        public ?string $city,
        public int $page,
        public CarbonImmutable $comparisonInstant,
    ) {
        if ($comparisonInstant->getOffset() !== 0) {
            throw new InvalidArgumentException('The comparison instant must use UTC.');
        }
    }

    public static function fromValidated(mixed $validated, CarbonImmutable $comparisonInstant): self
    {
        if (! is_array($validated)) {
            throw new InvalidArgumentException('The validated search criteria must be an array.');
        }

        $checkIn = $validated['check_in'] ?? null;
        $checkOut = $validated['check_out'] ?? null;
        $guests = self::canonicalInteger($validated['guests'] ?? null, 32_767);
        $city = $validated['city'] ?? null;
        $page = self::canonicalInteger($validated['page'] ?? 1, 2_147_483_647);

        if (
            ! is_string($checkIn)
            || ! is_string($checkOut)
            || (! is_string($city) && $city !== null)
        ) {
            throw new InvalidArgumentException('The validated search criteria are invalid.');
        }

        $checkInDate = CarbonImmutable::createFromFormat('!Y-m-d', $checkIn, 'UTC');
        $checkOutDate = CarbonImmutable::createFromFormat('!Y-m-d', $checkOut, 'UTC');

        if (! $checkInDate instanceof CarbonImmutable || ! $checkOutDate instanceof CarbonImmutable) {
            throw new InvalidArgumentException('The validated search dates are invalid.');
        }

        return new self($checkInDate, $checkOutDate, $guests, $city, $page, $comparisonInstant);
    }

    private static function canonicalInteger(mixed $value, int $maximum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
        } else {
            throw new InvalidArgumentException('The validated search integer is invalid.');
        }

        if (! is_int($integer) || $integer < 1 || $integer > $maximum) {
            throw new InvalidArgumentException('The validated search integer is out of range.');
        }

        return $integer;
    }
}
