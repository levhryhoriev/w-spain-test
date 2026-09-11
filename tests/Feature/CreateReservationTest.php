<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Imports\ProcessSupplierImport;
use App\Actions\Reservations\CreateReservation;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class CreateReservationTest extends TestCase
{
    use DatabaseMigrations;

    public function testRequestValidationNormalizesOnlyStringEmailsAndBoundsFields(): void
    {
        $offer = Offer::factory()->create();

        $this->postJson($this->reservationUrl($offer), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_reference', 'customer_name', 'customer_email']);
        $this->postJson($this->reservationUrl($offer), $this->payload([
            'client_reference' => str_repeat('r', 192),
            'customer_name' => str_repeat('n', 256),
            'customer_email' => str_repeat('e', 243) . '@example.com',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_reference', 'customer_name', 'customer_email']);
        $this->postJson($this->reservationUrl($offer), $this->payload(['customer_email' => ['not-a-string']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_email']);
        $this->postJson('/api/offers/999999/reservations', $this->payload())
            ->assertNotFound()
            ->assertJsonPath('message', 'No query results for model [App\\Models\\Offer] 999999');
    }

    public function testFirstReservationReturnsACompleteSnapshotAndConsumesOneUnit(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $this->travelTo($clock);
        $offer = Offer::factory()->create([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'price' => 72_500,
            'available_units' => 2,
            'expires_at' => $clock->addMicrosecond(),
        ]);

        $response = $this->postJson($this->reservationUrl($offer), $this->payload([
            'customer_email' => 'CUSTOMER@EXAMPLE.COM',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.offer_id', $offer->getKey())
            ->assertJsonPath('data.client_reference', 'web-order-1')
            ->assertJsonPath('data.customer_name', 'Jane Customer')
            ->assertJsonPath('data.customer_email', 'customer@example.com')
            ->assertJsonPath('data.check_in', '2026-10-10')
            ->assertJsonPath('data.check_out', '2026-10-15')
            ->assertJsonPath('data.price', 72_500)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.created_at', '2026-09-01T10:00:00.123456Z');
        $this->assertDatabaseHas('reservations', [
            'offer_id' => $offer->getKey(),
            'client_reference' => 'web-order-1',
            'customer_email' => 'customer@example.com',
            'price' => 72_500,
            'currency' => 'EUR',
        ]);
        self::assertSame(1, $offer->fresh()?->getAttribute('available_units'));
        $offer->forceFill(['price' => 99_999])->save();
        self::assertSame(72_500, Reservation::query()->sole()->getAttribute('price'));
    }

    public function testExactReplayReturnsExistingReservationAfterExpiryAndExhaustion(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $this->travelTo($clock);
        $offer = Offer::factory()->create([
            'available_units' => 1,
            'expires_at' => $clock->addSecond(),
        ]);
        $payload = $this->payload();
        $created = $this->postJson($this->reservationUrl($offer), $payload)->assertCreated();
        $reservationId = $created->json('data.id');
        self::assertIsInt($reservationId);
        $offer->forceFill([
            'available_units' => 0,
            'expires_at' => $clock,
        ])->save();

        $this->postJson($this->reservationUrl($offer), $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $reservationId);

        self::assertSame(1, Reservation::query()->count());
        self::assertSame(0, $offer->fresh()?->getAttribute('available_units'));
    }

    public function testReferenceConflictsAreJsonAndDoNotConsumeAdditionalUnits(): void
    {
        $firstOffer = Offer::factory()->create(['available_units' => 2]);
        $secondOffer = Offer::factory()->create(['available_units' => 2]);
        $payload = $this->payload();
        $this->postJson($this->reservationUrl($firstOffer), $payload)->assertCreated();

        $this->postJson($this->reservationUrl($firstOffer), $this->payload(['customer_name' => 'Other']))
            ->assertConflict()
            ->assertExactJson(['message' => 'Reservation conflict.']);
        $this->postJson($this->reservationUrl($secondOffer), $payload)
            ->assertConflict()
            ->assertExactJson(['message' => 'Reservation conflict.']);

        self::assertSame(1, Reservation::query()->count());
        self::assertSame(1, $firstOffer->fresh()?->getAttribute('available_units'));
        self::assertSame(2, $secondOffer->fresh()?->getAttribute('available_units'));
    }

    public function testReferencesRemainCaseSensitiveWhileEmailsNormalizeForReplay(): void
    {
        $offer = Offer::factory()->create(['available_units' => 3]);
        $this->postJson($this->reservationUrl($offer), $this->payload([
            'client_reference' => 'Case-Reference',
            'customer_email' => 'CUSTOMER@EXAMPLE.COM',
        ]))->assertCreated();
        $this->postJson($this->reservationUrl($offer), $this->payload([
            'client_reference' => 'Case-Reference',
            'customer_email' => 'customer@example.com',
        ]))->assertOk();
        $this->postJson($this->reservationUrl($offer), $this->payload([
            'client_reference' => 'case-reference',
        ]))->assertCreated();

        self::assertSame(2, Reservation::query()->count());
        self::assertSame(1, $offer->fresh()?->getAttribute('available_units'));
    }

    public function testExpiredExhaustedAndExactExpiryOffersAreRejectedWithoutWrites(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $this->travelTo($clock);

        foreach (
            [
                Offer::factory()->create(['available_units' => 0, 'expires_at' => $clock->addSecond()]),
                Offer::factory()->create(['available_units' => 1, 'expires_at' => $clock->subMicrosecond()]),
                Offer::factory()->create(['available_units' => 1, 'expires_at' => $clock]),
            ] as $offer
        ) {
            $this->postJson($this->reservationUrl($offer), $this->payload([
                'client_reference' => 'unavailable-' . $this->modelId($offer),
            ]))
                ->assertConflict()
                ->assertExactJson(['message' => 'Reservation conflict.']);
        }

        self::assertSame(0, Reservation::query()->count());
    }

    public function testSequentialLastUnitRequestsLeaveInventoryNonnegative(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);
        $this->postJson($this->reservationUrl($offer), $this->payload(['client_reference' => 'last-unit-a']))
            ->assertCreated();
        $this->postJson($this->reservationUrl($offer), $this->payload(['client_reference' => 'last-unit-b']))
            ->assertConflict();

        self::assertSame(1, Reservation::query()->count());
        self::assertSame(0, $offer->fresh()?->getAttribute('available_units'));
    }

    public function testUniqueReferenceRaceRollsBackTheRouteOfferDecrementAndReturnsConflict(): void
    {
        $routeOffer = Offer::factory()->create(['available_units' => 2]);
        $winningOffer = Offer::factory()->create(['available_units' => 2]);
        $payload = $this->payload(['client_reference' => 'cross-offer-race']);
        $connectionConfiguration = config('database.connections.mysql');
        self::assertIsArray($connectionConfiguration);
        config()->set('database.connections.reservation_race', $connectionConfiguration);
        $competitorInserted = false;
        DB::listen(static function (QueryExecuted $query) use (&$competitorInserted, $winningOffer, $payload): void {
            if ($competitorInserted || ! str_contains($query->sql, 'from `reservations`')) {
                return;
            }

            $competitorInserted = true;
            DB::connection('reservation_race')->table('reservations')->insert([
                'offer_id' => $winningOffer->getKey(),
                'client_reference' => $payload['client_reference'],
                'customer_name' => $payload['customer_name'],
                'customer_email' => $payload['customer_email'],
                'check_in' => $winningOffer->getAttribute('check_in'),
                'check_out' => $winningOffer->getAttribute('check_out'),
                'price' => $winningOffer->getAttribute('price'),
                'currency' => $winningOffer->getAttribute('currency'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson($this->reservationUrl($routeOffer), $payload)
            ->assertConflict()
            ->assertExactJson(['message' => 'Reservation conflict.']);
        DB::purge('reservation_race');

        self::assertTrue($competitorInserted);
        self::assertSame(1, Reservation::query()->count());
        self::assertSame(2, $routeOffer->fresh()?->getAttribute('available_units'));
    }

    public function testLaterSupplierSnapshotMayRestoreAuthoritativeAvailableUnits(): void
    {
        $clock = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $this->travelTo($clock);
        $offer = Offer::factory()->create([
            'available_units' => 2,
            'source_sent_at' => $clock,
        ]);
        $this->postJson($this->reservationUrl($offer), $this->payload())->assertCreated();
        $supplier = $offer->supplier()->firstOrFail();
        $property = $offer->property()->firstOrFail();
        $supplierImport = SupplierImport::factory()->create([
            'supplier_id' => $supplier->getKey(),
            'sent_at' => $clock->addSecond(),
            'payload' => [
                'offers' => [[
                    'external_id' => $offer->getAttribute('external_id'),
                    'property' => [
                        'code' => $property->getAttribute('code'),
                        'name' => $property->getAttribute('name'),
                        'city' => $property->getAttribute('city'),
                    ],
                    'check_in' => '2026-10-10',
                    'check_out' => '2026-10-15',
                    'max_guests' => 4,
                    'price' => 50_000,
                    'currency' => 'EUR',
                    'available_units' => 7,
                    'expires_at' => '2026-10-01T00:00:00.000000Z',
                ]],
            ],
        ]);

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));

        self::assertSame(7, $offer->fresh()?->getAttribute('available_units'));
    }

    public function testOfferUpdateFailureRollsBackTheCreatedReservationAndDecrement(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);
        $throwOnce = true;
        DB::listen(static function (QueryExecuted $query) use (&$throwOnce): void {
            if ($throwOnce && str_starts_with(strtolower($query->sql), 'update `offers`')) {
                $throwOnce = false;

                throw new RuntimeException('Injected Offer persistence failure.');
            }
        });

        try {
            app(CreateReservation::class)->execute(
                $this->modelId($offer),
                'rollback-reference',
                'Jane Customer',
                'customer@example.com',
            );
            self::fail('The injected Offer persistence failure must escape the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected Offer persistence failure.', $exception->getMessage());
        }

        self::assertSame(0, Reservation::query()->count());
        self::assertSame(2, $offer->fresh()?->getAttribute('available_units'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_reference' => 'web-order-1',
            'customer_name' => 'Jane Customer',
            'customer_email' => 'customer@example.com',
        ], $overrides);
    }

    private function reservationUrl(Offer $offer): string
    {
        return '/api/offers/' . $this->modelId($offer) . '/reservations';
    }

    private function modelId(Offer|SupplierImport $model): int
    {
        $id = $model->getKey();
        self::assertIsInt($id);

        return $id;
    }
}
