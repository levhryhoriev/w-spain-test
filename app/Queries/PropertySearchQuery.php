<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\PropertySearchCriteria;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final class PropertySearchQuery
{
    public function __construct(private readonly Connection $database)
    {
    }

    /** @return Paginator<int, stdClass> */
    public function execute(PropertySearchCriteria $criteria): Paginator
    {
        $rankedOffers = $this->eligibleOffers($criteria)
            ->select([
                'properties.id as property_id',
                'properties.code as property_code',
                'properties.name as property_name',
                'properties.city as property_city',
                'offers.id as offer_id',
                'suppliers.slug as supplier_slug',
                'offers.price as offer_price',
                'offers.currency as offer_currency',
                'offers.available_units as offer_available_units',
                'offers.expires_at as offer_expires_at',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price, offers.id) as offer_rank',
            );

        return $this->database->query()
            ->fromSub($rankedOffers, 'ranked_offers')
            ->where('offer_rank', 1)
            ->orderBy('offer_price')
            ->orderBy('property_code')
            ->orderBy('property_id')
            ->simplePaginate(15, ['*'], 'page', $criteria->page);
    }

    private function eligibleOffers(PropertySearchCriteria $criteria): Builder
    {
        return $this->database->table('offers')
            ->join('properties', 'properties.id', '=', 'offers.property_id')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->where('offers.check_in', $criteria->checkIn->toDateString())
            ->where('offers.check_out', $criteria->checkOut->toDateString())
            ->where('offers.max_guests', '>=', $criteria->guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', $criteria->comparisonInstant->format('Y-m-d H:i:s.u'))
            ->when(
                $criteria->city !== null,
                static fn (Builder $query): Builder => $query->where('properties.city', $criteria->city),
            );
    }
}
