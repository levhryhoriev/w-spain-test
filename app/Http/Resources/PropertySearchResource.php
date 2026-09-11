<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use stdClass;

final class PropertySearchResource extends JsonResource
{
    /** @return array<string, string|int|array<string, int|string>> */
    public function toArray(Request $request): array
    {
        $property = $this->resource;

        if (
            ! $property instanceof stdClass
            || ! is_string($property->property_code)
            || ! is_string($property->property_name)
            || ! is_string($property->property_city)
            || ! is_int($property->offer_id)
            || ! is_string($property->supplier_slug)
            || ! is_int($property->offer_price)
            || ! is_string($property->offer_currency)
            || ! is_int($property->offer_available_units)
            || ! is_string($property->offer_expires_at)
        ) {
            throw new LogicException('The property search result is invalid.');
        }

        return [
            'code' => $property->property_code,
            'name' => $property->property_name,
            'city' => $property->property_city,
            'best_offer' => [
                'id' => $property->offer_id,
                'supplier' => $property->supplier_slug,
                'price' => $property->offer_price,
                'currency' => $property->offer_currency,
                'available_units' => $property->offer_available_units,
                'expires_at' => CarbonImmutable::parse($property->offer_expires_at, 'UTC')
                    ->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ];
    }
}
