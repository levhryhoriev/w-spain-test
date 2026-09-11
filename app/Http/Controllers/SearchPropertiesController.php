<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PropertySearchRequest;
use App\Http\Resources\PropertySearchResource;
use App\Queries\PropertySearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SearchPropertiesController extends Controller
{
    public function __invoke(
        PropertySearchRequest $request,
        PropertySearchQuery $query,
    ): AnonymousResourceCollection {
        $criteria = $request->criteria(CarbonImmutable::now('UTC'));
        $results = $query->execute($criteria);
        $results->withQueryString();

        return PropertySearchResource::collection($results);
    }
}
