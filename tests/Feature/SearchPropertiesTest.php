<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SearchPropertiesTest extends TestCase
{
    use DatabaseMigrations;

    public function testSearchReturnsTheExactPublicRepresentationWithOneDatabaseQuery(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00.123456Z'));
        $supplier = Supplier::factory()->create(['slug' => 'supplier-a']);
        $property = Property::factory()->create([
            'code' => 'BCN-0001',
            'name' => 'Apartment near Sagrada Familia',
            'city' => 'Barcelona',
        ]);
        $offer = $this->createOffer($property, [
            'supplier_id' => $supplier->getKey(),
            'supplier_import_id' => $supplier->imports()->create([
                'external_import_id' => 'search-import',
                'sent_at' => now(),
                'status' => 'completed',
                'payload' => [],
                'total_offers' => 1,
                'processed_offers' => 1,
            ])->getKey(),
            'price' => 72_500,
            'expires_at' => CarbonImmutable::parse('2026-09-10T23:59:59.654321Z'),
        ]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson($this->searchUrl(['city' => 'Barcelona']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk()
            ->assertJsonPath('data', [[
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
                'best_offer' => [
                    'id' => $offer->getKey(),
                    'supplier' => 'supplier-a',
                    'price' => 72_500,
                    'currency' => 'EUR',
                    'available_units' => 2,
                    'expires_at' => '2026-09-10T23:59:59.654321Z',
                ],
            ]])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('links.prev', null);
        self::assertCount(1, $queries);
    }

    public function testHttpEligibilityExcludesEveryInvalidOfferAtTheSameFrozenInstant(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $this->travelTo($clock);
        $eligible = Property::factory()->create(['code' => 'ELIGIBLE', 'city' => 'Málaga']);
        $this->createOffer($eligible, [
            'max_guests' => 2,
            'available_units' => 1,
            'price' => 0,
            'expires_at' => $clock->addMicrosecond(),
        ]);

        foreach (
            [
                ['check_in' => '2026-10-09'],
                ['check_out' => '2026-10-16'],
                ['max_guests' => 1],
                ['available_units' => 0],
                ['expires_at' => $clock->subMicrosecond()],
                ['expires_at' => $clock],
            ] as $overrides
        ) {
            $this->createOffer(Property::factory()->create(['city' => 'Málaga']), $overrides);
        }

        $otherCity = Property::factory()->create(['code' => 'OTHER-CITY', 'city' => 'Madrid']);
        $this->createOffer($otherCity);

        $this->getJson($this->searchUrl(['city' => 'MALAGA']))
            ->assertOk()
            ->assertJsonPath('data.*.code', ['ELIGIBLE'])
            ->assertJsonPath('data.0.best_offer.price', 0)
            ->assertJsonPath('data.0.best_offer.available_units', 1)
            ->assertJsonPath('data.0.best_offer.expires_at', '2026-09-01T10:00:00.123457Z');
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('data.*.code', ['ELIGIBLE', 'OTHER-CITY']);
    }

    public function testInvalidQueryParametersReturnJsonValidationErrors(): void
    {
        $this->getJson('/api/properties')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in', 'check_out', 'guests']);

        foreach (
            [
                ['check_in', '2026-02-30'],
                ['check_in', '2026-10-10T00:00:00Z'],
                ['check_out', '2026-10-10'],
                ['check_out', '2026-10-09'],
                ['guests', '0'],
                ['guests', '32768'],
                ['guests', '1.0'],
                ['guests', '1e2'],
                ['guests', '01'],
                ['city', ''],
                ['city', str_repeat('x', 121)],
                ['page', '0'],
                ['page', '-1'],
                ['page', '1.0'],
                ['page', '2147483648'],
            ] as [$field, $value]
        ) {
            $this->getJson($this->searchUrl([$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->getJson($this->searchUrl() . '&guests[]=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guests']);
    }

    public function testEmptySearchHasTheRequiredPaginationEnvelope(): void
    {
        $this->getJson($this->searchUrl())
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('links.prev', null)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function testFirstMiddleAndLastPagesPreserveFiltersAndStableWinnerMembership(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00.123456Z'));
        $expectedCodes = [];

        for ($number = 32; $number >= 1; $number--) {
            $code = sprintf('P%02d', $number);
            $expectedCodes[] = $code;
            $property = Property::factory()->create(['code' => $code, 'city' => 'Barcelona']);
            $this->createOffer($property);
            $this->createOffer($property, ['price' => 20_000]);
        }

        sort($expectedCodes);
        $first = $this->getJson($this->searchUrl(['city' => 'Barcelona', 'page' => '1']))
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.*.code', array_slice($expectedCodes, 0, 15))
            ->assertJsonPath('links.prev', null)
            ->assertJsonPath('meta.per_page', 15);
        $nextUrl = $first->json('links.next');
        self::assertIsString($nextUrl);
        $this->assertSearchLink($nextUrl, 2);

        $middle = $this->getJson($nextUrl)
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.*.code', array_slice($expectedCodes, 15, 15))
            ->assertJsonPath('meta.current_page', 2);
        $previousUrl = $middle->json('links.prev');
        $lastUrl = $middle->json('links.next');
        self::assertIsString($previousUrl);
        self::assertIsString($lastUrl);
        $this->assertSearchLink($previousUrl, 1);
        $this->assertSearchLink($lastUrl, 3);

        $last = $this->getJson($lastUrl)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.*.code', array_slice($expectedCodes, 30))
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('meta.current_page', 3);
        $backUrl = $last->json('links.prev');
        self::assertIsString($backUrl);
        $this->assertSearchLink($backUrl, 2);
        $this->getJson($previousUrl)
            ->assertOk()
            ->assertJsonPath('data', $first->json('data'));
    }

    private function assertSearchLink(string $url, int $page): void
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parameters);
        self::assertSame([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => '2',
            'city' => 'Barcelona',
            'page' => (string) $page,
        ], $parameters);
    }

    /** @param array<string, string> $overrides */
    private function searchUrl(array $overrides = []): string
    {
        return '/api/properties?' . http_build_query(array_replace([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => '2',
        ], $overrides));
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
}
