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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function testModelsUseTheirExpectedTablesAndStatusVocabulary(): void
    {
        self::assertSame('suppliers', (new Supplier())->getTable());
        self::assertSame('properties', (new Property())->getTable());
        self::assertSame('supplier_imports', (new SupplierImport())->getTable());
        self::assertSame('offers', (new Offer())->getTable());
        self::assertSame('reservations', (new Reservation())->getTable());

        $statusValues = array_map(
            static fn (ImportStatus $status): string => $status->value,
            ImportStatus::cases(),
        );

        sort($statusValues);

        self::assertSame(['completed', 'failed', 'pending', 'processing'], $statusValues);
    }

    public function testEveryImportStatusPersistsAndCastsThroughEloquent(): void
    {
        $supplier = Supplier::create(['slug' => 'supplier-a']);

        foreach (ImportStatus::cases() as $status) {
            $supplierImport = SupplierImport::create([
                'supplier_id' => $supplier->getKey(),
                'external_import_id' => 'import-' . $status->value,
                'sent_at' => '2026-09-04 12:34:56.123456',
                'status' => $status,
                'payload' => ['offers' => []],
                'total_offers' => 0,
                'processed_offers' => 0,
            ]);

            $supplierImport->refresh();

            self::assertSame($status, $supplierImport->getAttribute('status'));
        }
    }

    public function testSupplierImportMassAssignmentAndCastsPreserveDomainValues(): void
    {
        $supplier = Supplier::create(['slug' => 'supplier-a']);
        $supplierImport = SupplierImport::create([
            'supplier_id' => $supplier->getKey(),
            'external_import_id' => 'import-1',
            'sent_at' => '2026-09-04 12:34:56.123456',
            'status' => ImportStatus::Pending,
            'payload' => ['offers' => []],
            'total_offers' => 1,
            'processed_offers' => 0,
            'error_type' => 'RuntimeException',
            'error_message' => 'Import processing failed.',
            'started_at' => '2026-09-04 12:35:01.234567',
            'completed_at' => '2026-09-04 12:35:02.345678',
        ]);

        $supplierImport->refresh();
        $supplierId = $supplier->getKey();

        self::assertIsInt($supplierId);
        self::assertSame($supplierId, $supplierImport->getAttribute('supplier_id'));
        self::assertSame(ImportStatus::Pending, $supplierImport->getAttribute('status'));
        self::assertSame(['offers' => []], $supplierImport->getAttribute('payload'));
        self::assertSame(1, $supplierImport->getAttribute('total_offers'));
        self::assertSame(0, $supplierImport->getAttribute('processed_offers'));
        self::assertSame('RuntimeException', $supplierImport->getAttribute('error_type'));
        self::assertSame('Import processing failed.', $supplierImport->getAttribute('error_message'));
        self::assertArrayNotHasKey('payload', $supplierImport->toArray());

        $sentAt = $supplierImport->getAttribute('sent_at');
        $startedAt = $supplierImport->getAttribute('started_at');
        $completedAt = $supplierImport->getAttribute('completed_at');

        self::assertInstanceOf(CarbonImmutable::class, $sentAt);
        self::assertInstanceOf(CarbonImmutable::class, $startedAt);
        self::assertInstanceOf(CarbonImmutable::class, $completedAt);
        self::assertSame('2026-09-04 12:34:56.123456', $sentAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-04 12:35:01.234567', $startedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-04 12:35:02.345678', $completedAt->format('Y-m-d H:i:s.u'));
    }

    public function testOfferMassAssignmentAndCastsPreserveDomainValues(): void
    {
        $supplier = Supplier::create(['slug' => 'supplier-a']);
        $property = Property::create([
            'code' => 'property-1',
            'name' => 'Test Property',
            'city' => 'Madrid',
        ]);
        $supplierImport = SupplierImport::create([
            'supplier_id' => $supplier->getKey(),
            'external_import_id' => 'import-1',
            'sent_at' => '2026-09-04 12:34:56.123456',
            'status' => ImportStatus::Pending,
            'payload' => ['offers' => []],
            'total_offers' => 1,
            'processed_offers' => 0,
        ]);
        $offer = Offer::create([
            'supplier_id' => $supplier->getKey(),
            'property_id' => $property->getKey(),
            'supplier_import_id' => $supplierImport->getKey(),
            'external_id' => 'offer-1',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'max_guests' => 2,
            'price' => 10_000,
            'currency' => 'EUR',
            'available_units' => 3,
            'expires_at' => '2026-09-30 12:34:56.234567',
            'source_sent_at' => '2026-09-04 12:34:56.123456',
        ]);

        $offer->refresh();
        $supplierId = $supplier->getKey();
        $propertyId = $property->getKey();
        $supplierImportId = $supplierImport->getKey();

        self::assertIsInt($supplierId);
        self::assertIsInt($propertyId);
        self::assertIsInt($supplierImportId);
        self::assertSame($supplierId, $offer->getAttribute('supplier_id'));
        self::assertSame($propertyId, $offer->getAttribute('property_id'));
        self::assertSame($supplierImportId, $offer->getAttribute('supplier_import_id'));
        self::assertSame(2, $offer->getAttribute('max_guests'));
        self::assertSame(10_000, $offer->getAttribute('price'));
        self::assertSame(3, $offer->getAttribute('available_units'));

        $checkIn = $offer->getAttribute('check_in');
        $checkOut = $offer->getAttribute('check_out');
        $expiresAt = $offer->getAttribute('expires_at');
        $sourceSentAt = $offer->getAttribute('source_sent_at');

        self::assertInstanceOf(CarbonImmutable::class, $checkIn);
        self::assertInstanceOf(CarbonImmutable::class, $checkOut);
        self::assertInstanceOf(CarbonImmutable::class, $expiresAt);
        self::assertInstanceOf(CarbonImmutable::class, $sourceSentAt);
        self::assertSame('2026-10-01', $checkIn->format('Y-m-d'));
        self::assertSame('2026-10-03', $checkOut->format('Y-m-d'));
        self::assertSame('2026-09-30 12:34:56.234567', $expiresAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-04 12:34:56.123456', $sourceSentAt->format('Y-m-d H:i:s.u'));
    }

    public function testReservationMassAssignmentAndCastsPreserveDomainValues(): void
    {
        $supplier = Supplier::create(['slug' => 'supplier-a']);
        $property = Property::create([
            'code' => 'property-1',
            'name' => 'Test Property',
            'city' => 'Madrid',
        ]);
        $supplierImport = SupplierImport::create([
            'supplier_id' => $supplier->getKey(),
            'external_import_id' => 'import-1',
            'sent_at' => '2026-09-04 12:34:56.123456',
            'status' => ImportStatus::Pending,
            'payload' => ['offers' => []],
            'total_offers' => 1,
            'processed_offers' => 0,
        ]);
        $offer = Offer::create([
            'supplier_id' => $supplier->getKey(),
            'property_id' => $property->getKey(),
            'supplier_import_id' => $supplierImport->getKey(),
            'external_id' => 'offer-1',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'max_guests' => 2,
            'price' => 10_000,
            'currency' => 'EUR',
            'available_units' => 3,
            'expires_at' => '2026-09-30 12:34:56.234567',
            'source_sent_at' => '2026-09-04 12:34:56.123456',
        ]);
        $reservation = Reservation::create([
            'offer_id' => $offer->getKey(),
            'client_reference' => 'reference-1',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'price' => 10_000,
            'currency' => 'EUR',
        ]);

        $reservation->refresh();
        $offerId = $offer->getKey();

        self::assertIsInt($offerId);
        self::assertSame($offerId, $reservation->getAttribute('offer_id'));
        self::assertSame(10_000, $reservation->getAttribute('price'));

        $checkIn = $reservation->getAttribute('check_in');
        $checkOut = $reservation->getAttribute('check_out');

        self::assertInstanceOf(CarbonImmutable::class, $checkIn);
        self::assertInstanceOf(CarbonImmutable::class, $checkOut);
        self::assertSame('2026-10-01', $checkIn->format('Y-m-d'));
        self::assertSame('2026-10-03', $checkOut->format('Y-m-d'));
    }

    public function testUsefulRelationshipsWorkInBothDirections(): void
    {
        $supplier = Supplier::create(['slug' => 'supplier-a']);
        $property = Property::create([
            'code' => 'property-1',
            'name' => 'Test Property',
            'city' => 'Madrid',
        ]);
        $supplierImport = SupplierImport::create([
            'supplier_id' => $supplier->getKey(),
            'external_import_id' => 'import-1',
            'sent_at' => '2026-09-04 12:00:00.000001',
            'status' => ImportStatus::Pending,
            'payload' => ['offers' => []],
            'total_offers' => 1,
            'processed_offers' => 0,
        ]);
        $offer = Offer::create([
            'supplier_id' => $supplier->getKey(),
            'property_id' => $property->getKey(),
            'supplier_import_id' => $supplierImport->getKey(),
            'external_id' => 'offer-1',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'max_guests' => 2,
            'price' => 10_000,
            'currency' => 'EUR',
            'available_units' => 1,
            'expires_at' => '2026-09-30 12:00:00.000001',
            'source_sent_at' => '2026-09-04 12:00:00.000001',
        ]);
        $reservation = Reservation::create([
            'offer_id' => $offer->getKey(),
            'client_reference' => 'reference-1',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'price' => 10_000,
            'currency' => 'EUR',
        ]);

        self::assertTrue($supplier->imports()->whereKey($supplierImport->getKey())->exists());
        self::assertTrue($supplier->offers()->whereKey($offer->getKey())->exists());
        self::assertTrue($property->offers()->whereKey($offer->getKey())->exists());
        self::assertTrue($supplierImport->supplier()->firstOrFail()->is($supplier));
        self::assertTrue($supplierImport->offers()->whereKey($offer->getKey())->exists());
        self::assertTrue($offer->supplier()->firstOrFail()->is($supplier));
        self::assertTrue($offer->property()->firstOrFail()->is($property));
        self::assertTrue($offer->supplierImport()->firstOrFail()->is($supplierImport));
        self::assertTrue($offer->reservations()->whereKey($reservation->getKey())->exists());
        self::assertTrue($reservation->offer()->firstOrFail()->is($offer));
        self::assertTrue($offer->supplierImport()->firstOrFail()->supplier()->firstOrFail()->is($supplier));
    }
}
