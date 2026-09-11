<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FactorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function testEveryModelFactoryCreatesAValidStandaloneRecord(): void
    {
        $supplier = Supplier::factory()->create();
        $property = Property::factory()->create();
        $supplierImport = SupplierImport::factory()->create();
        $offer = Offer::factory()->create();
        $reservation = Reservation::factory()->create();

        self::assertTrue($supplier->exists);
        self::assertTrue($property->exists);
        self::assertTrue($supplierImport->exists);
        self::assertTrue($offer->exists);
        self::assertTrue($reservation->exists);
        self::assertSame(
            $offer->supplierImport()->firstOrFail()->getAttribute('supplier_id'),
            $offer->getAttribute('supplier_id'),
        );
        self::assertTrue($reservation->offer()->firstOrFail()->isNot($offer));
    }

    public function testOfferCanBindAnExistingImportWithoutMismatchingItsSupplier(): void
    {
        $supplierImport = SupplierImport::factory()->create();
        $offer = Offer::factory()->forSupplierImport($supplierImport)->create();

        self::assertTrue($offer->supplierImport()->firstOrFail()->is($supplierImport));
        self::assertTrue($offer->supplier()->firstOrFail()->is($supplierImport->supplier()->firstOrFail()));
    }

    public function testReservationSnapshotsItsOffersCommercialTerms(): void
    {
        $offer = Offer::factory()->pricedAt(12_345)->create();
        $reservation = Reservation::factory()->for($offer)->create();
        $offerCheckIn = $offer->getAttribute('check_in');
        $offerCheckOut = $offer->getAttribute('check_out');
        $reservationCheckIn = $reservation->getAttribute('check_in');
        $reservationCheckOut = $reservation->getAttribute('check_out');

        self::assertInstanceOf(CarbonImmutable::class, $offerCheckIn);
        self::assertInstanceOf(CarbonImmutable::class, $offerCheckOut);
        self::assertInstanceOf(CarbonImmutable::class, $reservationCheckIn);
        self::assertInstanceOf(CarbonImmutable::class, $reservationCheckOut);
        self::assertTrue($offerCheckIn->equalTo($reservationCheckIn));
        self::assertTrue($offerCheckOut->equalTo($reservationCheckOut));
        self::assertSame(12_345, $reservation->getAttribute('price'));
        self::assertSame('EUR', $reservation->getAttribute('currency'));
    }

    public function testSupplierImportFactoriesProduceCoherentLifecycleStates(): void
    {
        $pending = SupplierImport::factory()->create();
        $processing = SupplierImport::factory()->processing()->create();
        $completed = SupplierImport::factory()->completed()->create();
        $failed = SupplierImport::factory()->failed()->create();

        self::assertSame(ImportStatus::Pending, $pending->getAttribute('status'));
        self::assertSame(0, $pending->getAttribute('total_offers'));
        self::assertSame(0, $pending->getAttribute('processed_offers'));
        self::assertNull($pending->getAttribute('started_at'));
        self::assertNull($pending->getAttribute('completed_at'));
        self::assertNull($pending->getAttribute('error_type'));
        self::assertSame(ImportStatus::Processing, $processing->getAttribute('status'));
        self::assertSame(0, $processing->getAttribute('total_offers'));
        self::assertSame(0, $processing->getAttribute('processed_offers'));
        self::assertNotNull($processing->getAttribute('started_at'));
        self::assertNull($processing->getAttribute('completed_at'));
        self::assertNull($processing->getAttribute('error_type'));
        self::assertSame(ImportStatus::Completed, $completed->getAttribute('status'));
        self::assertSame($completed->getAttribute('total_offers'), $completed->getAttribute('processed_offers'));
        self::assertNotNull($completed->getAttribute('started_at'));
        self::assertNotNull($completed->getAttribute('completed_at'));
        self::assertNull($completed->getAttribute('error_type'));
        self::assertSame(ImportStatus::Failed, $failed->getAttribute('status'));
        self::assertSame(0, $failed->getAttribute('total_offers'));
        self::assertSame(0, $failed->getAttribute('processed_offers'));
        self::assertNotNull($failed->getAttribute('started_at'));
        self::assertNull($failed->getAttribute('completed_at'));
        self::assertNotNull($failed->getAttribute('error_type'));
        self::assertNotNull($failed->getAttribute('error_message'));
    }

    public function testOfferFactoriesProduceEligibilityAndComparisonStates(): void
    {
        $sentAt = CarbonImmutable::parse('2026-09-04 12:34:56.123456');
        $offer = Offer::factory()->pricedAt(9_999)->sourcedAt($sentAt)->create();
        $expired = Offer::factory()->expired()->create();
        $exhausted = Offer::factory()->exhausted()->create();
        $sourceSentAt = $offer->getAttribute('source_sent_at');
        $expiresAt = $expired->getAttribute('expires_at');

        self::assertInstanceOf(CarbonImmutable::class, $sourceSentAt);
        self::assertInstanceOf(CarbonImmutable::class, $expiresAt);
        self::assertSame(9_999, $offer->getAttribute('price'));
        self::assertTrue($sentAt->equalTo($sourceSentAt));
        self::assertTrue($expiresAt->isPast());
        self::assertSame(0, $exhausted->getAttribute('available_units'));
    }

    public function testRepeatedDatabaseSeedingConvergesWithoutChangingUnrelatedSuppliers(): void
    {
        $unrelated = Supplier::factory()->create(['slug' => 'Supplier-A']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        self::assertSame(1, Supplier::query()->where('slug', 'supplier-a')->count());
        self::assertSame(1, Supplier::query()->where('slug', 'supplier-b')->count());
        self::assertTrue(Supplier::query()->whereKey($unrelated->getKey())->where('slug', 'Supplier-A')->exists());
        self::assertSame(3, Supplier::query()->count());
    }
}
