<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\PropertySearchCriteria;
use App\Models\Offer;
use App\Models\Property;
use App\Queries\PropertySearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

final class PropertySearchQueryTest extends TestCase
{
    use DatabaseMigrations;

    public function testEveryEligibilityPredicateIsAppliedIndependently(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $valid = Property::factory()->create(['code' => 'VALID']);
        $wrongCheckIn = Property::factory()->create(['code' => 'WRONG-IN']);
        $wrongCheckOut = Property::factory()->create(['code' => 'WRONG-OUT']);
        $tooFewGuests = Property::factory()->create(['code' => 'TOO-SMALL']);
        $exhausted = Property::factory()->create(['code' => 'EXHAUSTED']);
        $expired = Property::factory()->create(['code' => 'EXPIRED']);
        $exactExpiry = Property::factory()->create(['code' => 'EXACT-EXPIRY']);
        $this->travelTo($clock);
        $this->createOffer($valid, [
            'max_guests' => 2,
            'available_units' => 1,
            'price' => 0,
            'expires_at' => $clock->addMicrosecond(),
        ]);
        $this->createOffer($wrongCheckIn, ['check_in' => '2026-10-09']);
        $this->createOffer($wrongCheckOut, ['check_out' => '2026-10-16']);
        $this->createOffer($tooFewGuests, ['max_guests' => 1]);
        $this->createOffer($exhausted, ['available_units' => 0]);
        $this->createOffer($expired, ['expires_at' => $clock->subMicrosecond()]);
        $this->createOffer($exactExpiry, ['expires_at' => $clock]);

        $results = $this->search($clock);

        self::assertSame(['VALID'], $this->propertyCodes($results));
    }

    public function testCheapestOfferWinsWithLowestOfferIdentityAsPriceTieBreaker(): void
    {
        $property = Property::factory()->create(['code' => 'CHEAPEST']);
        $this->createOffer($property, ['price' => 30_000]);
        $firstCheapest = $this->createOffer($property, ['price' => 10_000]);
        $this->createOffer($property, ['price' => 10_000]);

        $result = $this->soleResult($this->search());

        self::assertSame($firstCheapest->getKey(), $result->offer_id);
        self::assertSame(10_000, $result->offer_price);
        self::assertSame('EUR', $result->offer_currency);
        self::assertIsString($result->supplier_slug);
        self::assertSame(2, $result->offer_available_units);
        self::assertNotEmpty($result->offer_expires_at);
    }

    public function testIneligibleCheapOffersCannotHideAnEligibleWinner(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $property = Property::factory()->create(['code' => 'ELIGIBLE-WINNER']);
        $this->createOffer($property, ['price' => 0, 'check_in' => '2026-10-09']);
        $this->createOffer($property, ['price' => 0, 'check_out' => '2026-10-16']);
        $this->createOffer($property, ['price' => 0, 'max_guests' => 1]);
        $this->createOffer($property, ['price' => 0, 'available_units' => 0]);
        $this->createOffer($property, ['price' => 0, 'expires_at' => $clock]);
        $winner = $this->createOffer($property, ['price' => 50_000]);

        self::assertSame($winner->getKey(), $this->soleResult($this->search($clock))->offer_id);
    }

    public function testWinningPricePrecedesPropertyCodeInResultOrdering(): void
    {
        $alpha = Property::factory()->create(['code' => 'ALPHA']);
        $zulu = Property::factory()->create(['code' => 'ZULU']);
        $beta = Property::factory()->create(['code' => 'BETA']);
        $this->createOffer($alpha, ['price' => 30_000]);
        $this->createOffer($zulu, ['price' => 10_000]);
        $this->createOffer($beta, ['price' => 30_000]);

        self::assertSame(['ZULU', 'ALPHA', 'BETA'], $this->propertyCodes($this->search()));
    }

    public function testEmptyResultsAndPagesBeyondTheLastPageRemainEmpty(): void
    {
        Property::factory()->create(['code' => 'NO-OFFERS']);
        $empty = $this->search();
        self::assertSame([], $empty->items());
        self::assertFalse($empty->hasMorePages());

        $property = Property::factory()->create(['code' => 'ONE-OFFER']);
        $this->createOffer($property);
        self::assertSame([], $this->search(city: 'No matching city')->items());
        self::assertSame([], $this->search(page: 2)->items());
    }

    public function testCityFilterUsesMySqlCaseAndAccentInsensitiveCollationAndIsOptional(): void
    {
        $malaga = Property::factory()->create(['code' => 'MALAGA', 'city' => 'Málaga']);
        $madrid = Property::factory()->create(['code' => 'MADRID', 'city' => 'Madrid']);
        $this->createOffer($malaga);
        $this->createOffer($madrid);

        self::assertSame(['MADRID', 'MALAGA'], $this->propertyCodes($this->search()));
        self::assertSame(['MALAGA'], $this->propertyCodes($this->search(city: 'malaga')));
        self::assertSame(['MALAGA'], $this->propertyCodes($this->search(city: 'MÁLAGA')));
    }

    public function testWinnerReductionPrecedesStableDatabasePagination(): void
    {
        $expectedCodes = [];

        for ($number = 1; $number <= 17; $number++) {
            $code = sprintf('P%02d', $number);
            $expectedCodes[] = $code;
            $property = Property::factory()->create(['code' => $code]);
            $this->createOffer($property, ['price' => 10_000]);
            $this->createOffer($property, ['price' => 20_000]);
        }

        $firstPage = $this->search(page: 1);
        $secondPage = $this->search(page: 2);
        $repeatedFirstPage = $this->search(page: 1);
        $firstCodes = $this->propertyCodes($firstPage);
        $secondCodes = $this->propertyCodes($secondPage);

        self::assertSame(array_slice($expectedCodes, 0, 15), $firstCodes);
        self::assertSame(array_slice($expectedCodes, 15), $secondCodes);
        self::assertSame($firstCodes, $this->propertyCodes($repeatedFirstPage));
        self::assertSame([], array_intersect($firstCodes, $secondCodes));
        self::assertSame($expectedCodes, array_merge($firstCodes, $secondCodes));
        self::assertCount(15, $firstPage->items());
        self::assertCount(2, $secondPage->items());
        self::assertTrue($firstPage->hasMorePages());
        self::assertFalse($secondPage->hasMorePages());
        self::assertSame(15, $firstPage->perPage());
    }

    public function testSearchExecutesOneWindowedDatabaseQueryWithoutCountOrLazyLoads(): void
    {
        $property = Property::factory()->create(['code' => 'QUERY']);
        $this->createOffer($property);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $results = $this->search();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertCount(1, $queries);
        self::assertCount(1, $results->items());
        $sql = strtolower($queries[0]['query']);
        self::assertStringContainsString('row_number() over', $sql);
        self::assertStringContainsString('partition by offers.property_id', $sql);
        self::assertStringContainsString('`offer_rank` = ?', $sql);
        self::assertStringNotContainsString('count(', $sql);
        self::assertStringContainsString('limit 16', $sql);
    }

    /** @param array<string, mixed> $overrides */
    private function createOffer(Property $property, array $overrides = []): Offer
    {
        return Offer::factory()->for($property)->create(array_merge([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 10_000,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => CarbonImmutable::parse('2026-09-02T10:00:00Z'),
        ], $overrides));
    }

    /** @return Paginator<int, stdClass> */
    private function search(
        ?CarbonImmutable $clock = null,
        ?string $city = null,
        int $page = 1,
    ): Paginator {
        $criteria = PropertySearchCriteria::fromValidated([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
            'city' => $city,
            'page' => $page,
        ], $clock ?? CarbonImmutable::parse('2026-09-01T10:00:00.123456Z'));

        return app(PropertySearchQuery::class)->execute($criteria);
    }

    /**
     * @param Paginator<int, stdClass> $results
     * @return array<int, string>
     */
    private function propertyCodes(Paginator $results): array
    {
        return array_map(
            static function (mixed $item): string {
                self::assertInstanceOf(stdClass::class, $item);
                self::assertIsString($item->property_code);

                return $item->property_code;
            },
            $results->items(),
        );
    }

    /** @param Paginator<int, stdClass> $results */
    private function soleResult(Paginator $results): stdClass
    {
        self::assertCount(1, $results->items());
        $result = $results->items()[0];
        self::assertInstanceOf(stdClass::class, $result);

        return $result;
    }
}
