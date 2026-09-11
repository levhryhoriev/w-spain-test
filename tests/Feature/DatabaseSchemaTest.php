<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class DatabaseSchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function testMigrationsRollBackAndRunAgain(): void
    {
        self::assertTrue(Schema::hasTable('suppliers'));
        self::assertTrue(Schema::hasTable('properties'));
        self::assertTrue(Schema::hasTable('supplier_imports'));
        self::assertTrue(Schema::hasTable('offers'));
        self::assertTrue(Schema::hasTable('reservations'));

        $rollback = $this->artisan('migrate:rollback', ['--force' => true]);

        self::assertInstanceOf(PendingCommand::class, $rollback);
        $rollback->assertSuccessful();
        $rollback->execute();

        self::assertFalse(Schema::hasTable('suppliers'));
        self::assertFalse(Schema::hasTable('properties'));
        self::assertFalse(Schema::hasTable('supplier_imports'));
        self::assertFalse(Schema::hasTable('offers'));
        self::assertFalse(Schema::hasTable('reservations'));

        $migrate = $this->artisan('migrate', ['--force' => true]);

        self::assertInstanceOf(PendingCommand::class, $migrate);
        $migrate->assertSuccessful();
        $migrate->execute();

        self::assertTrue(Schema::hasTable('suppliers'));
        self::assertTrue(Schema::hasTable('properties'));
        self::assertTrue(Schema::hasTable('supplier_imports'));
        self::assertTrue(Schema::hasTable('offers'));
        self::assertTrue(Schema::hasTable('reservations'));
    }

    public function testShowCreateTableExposesTheSelectedSchemaContract(): void
    {
        $suppliers = $this->showCreateTable('suppliers');
        $properties = $this->showCreateTable('properties');
        $imports = $this->showCreateTable('supplier_imports');
        $offers = $this->showCreateTable('offers');
        $reservations = $this->showCreateTable('reservations');

        self::assertStringContainsString(
            '`slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs',
            $suppliers,
        );
        self::assertStringContainsString('UNIQUE KEY `uq_suppliers_slug`', $suppliers);
        self::assertStringContainsString(
            '`code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs',
            $properties,
        );
        self::assertStringContainsString(
            '`city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
            $properties,
        );
        self::assertStringContainsString('UNIQUE KEY `uq_properties_code`', $properties);

        self::assertStringContainsString('`payload` json NOT NULL', $imports);
        self::assertStringContainsString('`sent_at` datetime(6) NOT NULL', $imports);
        self::assertStringContainsString('`started_at` datetime(6) DEFAULT NULL', $imports);
        self::assertStringContainsString('`completed_at` datetime(6) DEFAULT NULL', $imports);
        self::assertStringContainsString(
            '`external_import_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs',
            $imports,
        );
        self::assertStringContainsString(
            '`status` varchar(20) CHARACTER SET ascii COLLATE ascii_bin',
            $imports,
        );
        self::assertStringContainsString('UNIQUE KEY `uq_supplier_imports_supplier_external`', $imports);
        self::assertStringContainsString('UNIQUE KEY `uq_supplier_imports_supplier_id_id`', $imports);
        self::assertStringContainsString('CONSTRAINT `chk_supplier_imports_status`', $imports);
        self::assertStringContainsString('CONSTRAINT `chk_supplier_imports_total_offers`', $imports);
        self::assertStringContainsString('CONSTRAINT `chk_supplier_imports_processed_offers`', $imports);
        self::assertStringContainsString('CONSTRAINT `fk_supplier_imports_supplier`', $imports);

        self::assertStringContainsString(
            '`external_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs',
            $offers,
        );
        self::assertStringContainsString('`price` bigint NOT NULL', $offers);
        self::assertStringContainsString('`expires_at` datetime(6) NOT NULL', $offers);
        self::assertStringContainsString('`source_sent_at` datetime(6) NOT NULL', $offers);
        self::assertStringContainsString(
            '`currency` char(3) CHARACTER SET ascii COLLATE ascii_bin',
            $offers,
        );
        self::assertStringContainsString(
            'UNIQUE KEY `uq_offers_supplier_external` (`supplier_id`,`external_id`)',
            $offers,
        );
        self::assertStringNotContainsString('idx_offers_search_hypothesis', $offers);
        self::assertStringContainsString('CONSTRAINT `fk_offers_supplier`', $offers);
        self::assertStringContainsString('CONSTRAINT `fk_offers_property`', $offers);
        self::assertStringContainsString('CONSTRAINT `fk_offers_supplier_import`', $offers);
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `supplier_import_id`) '
            . 'REFERENCES `supplier_imports` (`supplier_id`, `id`)',
            $offers,
        );
        self::assertStringContainsString('CONSTRAINT `chk_offers_stay_dates`', $offers);
        self::assertStringContainsString('CONSTRAINT `chk_offers_max_guests`', $offers);
        self::assertStringContainsString('CONSTRAINT `chk_offers_price`', $offers);
        self::assertStringContainsString('CONSTRAINT `chk_offers_available_units`', $offers);
        self::assertStringContainsString('CONSTRAINT `chk_offers_currency`', $offers);

        self::assertStringContainsString(
            '`client_reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs',
            $reservations,
        );
        self::assertStringContainsString('`check_in` date NOT NULL', $reservations);
        self::assertStringContainsString('`check_out` date NOT NULL', $reservations);
        self::assertStringContainsString('`price` bigint NOT NULL', $reservations);
        self::assertStringContainsString(
            '`currency` char(3) CHARACTER SET ascii COLLATE ascii_bin',
            $reservations,
        );
        self::assertStringContainsString('UNIQUE KEY `uq_reservations_client_reference`', $reservations);
        self::assertStringContainsString('CONSTRAINT `fk_reservations_offer`', $reservations);
        self::assertStringContainsString('CONSTRAINT `chk_reservations_stay_dates`', $reservations);
        self::assertStringContainsString('CONSTRAINT `chk_reservations_price`', $reservations);
        self::assertStringContainsString('CONSTRAINT `chk_reservations_currency`', $reservations);

        foreach ([$suppliers, $properties, $imports, $offers, $reservations] as $definition) {
            self::assertStringContainsString('`created_at` datetime(6) DEFAULT NULL', $definition);
            self::assertStringContainsString('`updated_at` datetime(6) DEFAULT NULL', $definition);
            self::assertStringContainsString('ENGINE=InnoDB', $definition);
            self::assertStringNotContainsString('CASCADE', $definition);
        }
    }

    public function testIdentityColumnsAreCaseSensitiveAndCityIsCaseInsensitive(): void
    {
        $firstSupplierId = $this->createSupplier('supplier-a');
        $secondSupplierId = $this->createSupplier('Supplier-A');
        $firstPropertyId = $this->createProperty('CAFE-1', 'Málaga');
        $accentedPropertyId = $this->createProperty('CAFÉ-1', 'Seville');
        $secondPropertyId = $this->createProperty('cafe-1', 'Valencia');
        $firstImportId = $this->createImport($firstSupplierId, 'IMPORT-1');
        $secondImportId = $this->createImport($firstSupplierId, 'import-1');
        $firstOfferId = $this->createOffer($firstSupplierId, $firstPropertyId, $firstImportId, 'OFFER-1');
        $secondOfferId = $this->createOffer($firstSupplierId, $secondPropertyId, $secondImportId, 'offer-1');

        $this->createReservation($firstOfferId, 'REFERENCE-1');
        $this->createReservation($secondOfferId, 'reference-1');

        self::assertNotSame($firstSupplierId, $secondSupplierId);
        self::assertNotSame($firstPropertyId, $accentedPropertyId);
        self::assertNotSame($firstPropertyId, $secondPropertyId);
        self::assertNotSame($firstImportId, $secondImportId);
        self::assertNotSame($firstOfferId, $secondOfferId);
        self::assertSame(2, DB::table('reservations')->count());
        self::assertSame(1, DB::table('properties')->where('city', 'malaga')->count());
    }

    public function testEveryUniqueScopeIsEnforced(): void
    {
        $firstSupplierId = $this->createSupplier('supplier-a');
        $secondSupplierId = $this->createSupplier('supplier-b');
        $propertyId = $this->createProperty('property-1', 'Madrid');

        $this->assertDatabaseRejects(
            fn (): int => $this->createSupplier('supplier-a'),
            'uq_suppliers_slug',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createProperty('property-1', 'Barcelona'),
            'uq_properties_code',
        );

        $firstImportId = $this->createImport($firstSupplierId, 'import-1');
        $laterImportId = $this->createImport($firstSupplierId, 'import-2');
        $secondImportId = $this->createImport($secondSupplierId, 'import-1');
        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($firstSupplierId, 'import-1'),
            'uq_supplier_imports_supplier_external',
        );

        $firstOfferId = $this->createOffer($firstSupplierId, $propertyId, $firstImportId, 'offer-1');
        $secondOfferId = $this->createOffer($secondSupplierId, $propertyId, $secondImportId, 'offer-1');
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer($firstSupplierId, $propertyId, $laterImportId, 'offer-1'),
            'uq_offers_supplier_external',
        );

        $this->createReservation($firstOfferId, 'reference-1');
        $this->assertDatabaseRejects(
            fn (): int => $this->createReservation($secondOfferId, 'reference-1'),
            'uq_reservations_client_reference',
        );
    }

    public function testForeignKeysRejectOrphanRows(): void
    {
        $supplierId = $this->createSupplier('supplier-a');
        $propertyId = $this->createProperty('property-1', 'Madrid');
        $importId = $this->createImport($supplierId, 'import-1');

        $this->assertDatabaseRejects(
            fn (): int => $this->createImport(999_999, 'orphan-import'),
            'fk_supplier_imports_supplier',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer($supplierId, 999_999, $importId, 'orphan-property'),
            'fk_offers_property',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer($supplierId, $propertyId, 999_999, 'orphan-import'),
            'fk_offers_supplier_import',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createReservation(999_999, 'orphan-offer'),
            'fk_reservations_offer',
        );
    }

    public function testForeignKeysRestrictDeletionOfReferencedRows(): void
    {
        $supplierId = $this->createSupplier('supplier-a');
        $propertyId = $this->createProperty('property-1', 'Madrid');
        $importId = $this->createImport($supplierId, 'import-1');
        $offerId = $this->createOffer($supplierId, $propertyId, $importId, 'offer-1');

        $this->createReservation($offerId, 'reference-1');

        $this->assertDatabaseRejects(
            fn (): int => DB::table('suppliers')->where('id', $supplierId)->delete(),
            'Cannot delete or update a parent row',
        );
        $this->assertDatabaseRejects(
            fn (): int => DB::table('properties')->where('id', $propertyId)->delete(),
            'fk_offers_property',
        );
        $this->assertDatabaseRejects(
            fn (): int => DB::table('supplier_imports')->where('id', $importId)->delete(),
            'fk_offers_supplier_import',
        );
        $this->assertDatabaseRejects(
            fn (): int => DB::table('offers')->where('id', $offerId)->delete(),
            'fk_reservations_offer',
        );
    }

    public function testImportChecksAndJsonStorageAreEnforced(): void
    {
        $supplierId = $this->createSupplier('supplier-a');

        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($supplierId, 'bad-status', 'PENDING'),
            'chk_supplier_imports_status',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($supplierId, 'negative-total', 'pending', -1),
            'Check constraint',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($supplierId, 'oversized-total', 'pending', 501),
            'chk_supplier_imports_total_offers',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($supplierId, 'negative-processed', 'pending', 1, -1),
            'chk_supplier_imports_processed_offers',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($supplierId, 'excess-processed', 'pending', 1, 2),
            'chk_supplier_imports_processed_offers',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createImport($supplierId, 'invalid-json', 'pending', 0, 0, 'invalid'),
            'Invalid JSON text',
        );

        foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
            $this->createImport($supplierId, 'valid-' . $status, $status);
        }

        self::assertSame(4, DB::table('supplier_imports')->count());
    }

    public function testDatetimeMicrosecondsSurviveADatabaseRoundTrip(): void
    {
        $supplierId = $this->createSupplier('supplier-a');
        $importId = $this->createImport($supplierId, 'import-1');
        $instant = '2026-09-04 12:34:56.123456';

        DB::table('supplier_imports')->where('id', $importId)->update([
            'started_at' => $instant,
            'completed_at' => $instant,
        ]);

        self::assertSame(
            $instant,
            DB::table('supplier_imports')->where('id', $importId)->value('started_at'),
        );
        self::assertSame(
            $instant,
            DB::table('supplier_imports')->where('id', $importId)->value('completed_at'),
        );
    }

    public function testOfferChecksAreEnforced(): void
    {
        $supplierId = $this->createSupplier('supplier-a');
        $propertyId = $this->createProperty('property-1', 'Madrid');
        $importId = $this->createImport($supplierId, 'import-1');

        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $supplierId,
                $propertyId,
                $importId,
                'bad-stay',
                '2026-10-03',
                '2026-10-03',
            ),
            'chk_offers_stay_dates',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $supplierId,
                $propertyId,
                $importId,
                'bad-capacity',
                '2026-10-01',
                '2026-10-03',
                0,
            ),
            'chk_offers_max_guests',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $supplierId,
                $propertyId,
                $importId,
                'bad-price',
                '2026-10-01',
                '2026-10-03',
                2,
                -1,
            ),
            'chk_offers_price',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $supplierId,
                $propertyId,
                $importId,
                'bad-units',
                '2026-10-01',
                '2026-10-03',
                2,
                10_000,
                'EUR',
                -1,
            ),
            'chk_offers_available_units',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $supplierId,
                $propertyId,
                $importId,
                'bad-currency',
                '2026-10-01',
                '2026-10-03',
                2,
                10_000,
                'USD',
            ),
            'chk_offers_currency',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $supplierId,
                $propertyId,
                $importId,
                'lowercase-currency',
                '2026-10-01',
                '2026-10-03',
                2,
                10_000,
                'eur',
            ),
            'chk_offers_currency',
        );
    }

    public function testReservationSnapshotChecksAreEnforced(): void
    {
        $supplierId = $this->createSupplier('supplier-a');
        $propertyId = $this->createProperty('property-1', 'Madrid');
        $importId = $this->createImport($supplierId, 'import-1');
        $offerId = $this->createOffer($supplierId, $propertyId, $importId, 'offer-1');

        $this->assertDatabaseRejects(
            fn (): int => $this->createReservation(
                $offerId,
                'bad-stay',
                '2026-10-03',
                '2026-10-03',
            ),
            'chk_reservations_stay_dates',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createReservation(
                $offerId,
                'bad-price',
                '2026-10-01',
                '2026-10-03',
                -1,
            ),
            'chk_reservations_price',
        );
        $this->assertDatabaseRejects(
            fn (): int => $this->createReservation(
                $offerId,
                'bad-currency',
                '2026-10-01',
                '2026-10-03',
                10_000,
                'USD',
            ),
            'chk_reservations_currency',
        );
    }

    public function testOfferSupplierMustMatchItsImportSupplier(): void
    {
        $firstSupplierId = $this->createSupplier('supplier-a');
        $secondSupplierId = $this->createSupplier('supplier-b');
        $propertyId = $this->createProperty('property-1', 'Madrid');
        $firstImportId = $this->createImport($firstSupplierId, 'import-1');

        $this->assertDatabaseRejects(
            fn (): int => $this->createOffer(
                $secondSupplierId,
                $propertyId,
                $firstImportId,
                'mismatched-offer',
            ),
            'fk_offers_supplier_import',
        );
    }

    private function createSupplier(string $slug): int
    {
        return (int) DB::table('suppliers')->insertGetId(['slug' => $slug]);
    }

    private function createProperty(string $code, string $city): int
    {
        return (int) DB::table('properties')->insertGetId([
            'code' => $code,
            'name' => 'Test Property',
            'city' => $city,
        ]);
    }

    private function createImport(
        int $supplierId,
        string $externalImportId,
        string $status = 'pending',
        int $totalOffers = 0,
        int $processedOffers = 0,
        string $payload = '{"offers":[]}',
    ): int {
        return (int) DB::table('supplier_imports')->insertGetId([
            'supplier_id' => $supplierId,
            'external_import_id' => $externalImportId,
            'sent_at' => '2026-09-04 12:00:00.123456',
            'status' => $status,
            'payload' => $payload,
            'total_offers' => $totalOffers,
            'processed_offers' => $processedOffers,
        ]);
    }

    private function createOffer(
        int $supplierId,
        int $propertyId,
        int $supplierImportId,
        string $externalId,
        string $checkIn = '2026-10-01',
        string $checkOut = '2026-10-03',
        int $maxGuests = 2,
        int $price = 10_000,
        string $currency = 'EUR',
        int $availableUnits = 1,
    ): int {
        return (int) DB::table('offers')->insertGetId([
            'supplier_id' => $supplierId,
            'property_id' => $propertyId,
            'supplier_import_id' => $supplierImportId,
            'external_id' => $externalId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'max_guests' => $maxGuests,
            'price' => $price,
            'currency' => $currency,
            'available_units' => $availableUnits,
            'expires_at' => '2026-09-30 12:00:00.123456',
            'source_sent_at' => '2026-09-04 12:00:00.123456',
        ]);
    }

    private function createReservation(
        int $offerId,
        string $clientReference,
        string $checkIn = '2026-10-01',
        string $checkOut = '2026-10-03',
        int $price = 10_000,
        string $currency = 'EUR',
    ): int {
        return (int) DB::table('reservations')->insertGetId([
            'offer_id' => $offerId,
            'client_reference' => $clientReference,
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'price' => $price,
            'currency' => $currency,
        ]);
    }

    private function assertDatabaseRejects(Closure $write, string $expectedMessage): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            self::assertStringContainsString($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('The database accepted an invalid write.');
    }

    private function showCreateTable(string $table): string
    {
        $statement = match ($table) {
            'suppliers' => 'SHOW CREATE TABLE suppliers',
            'properties' => 'SHOW CREATE TABLE properties',
            'supplier_imports' => 'SHOW CREATE TABLE supplier_imports',
            'offers' => 'SHOW CREATE TABLE offers',
            'reservations' => 'SHOW CREATE TABLE reservations',
            default => throw new \InvalidArgumentException($table),
        };
        $row = DB::selectOne($statement);

        self::assertNotNull($row);

        $definition = (array) $row;
        $createTable = $definition['Create Table'] ?? null;

        self::assertIsString($createTable);

        return $createTable;
    }
}
