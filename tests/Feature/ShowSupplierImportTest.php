<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

final class ShowSupplierImportTest extends TestCase
{
    use DatabaseMigrations;

    public function testEveryImportStateHasTheExactCurrentResourceRepresentation(): void
    {
        $supplier = Supplier::factory()->create(['slug' => 'supplier-a']);
        $sentAt = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $createdAt = CarbonImmutable::parse('2026-09-01T10:00:02.234567Z');
        $completedAt = CarbonImmutable::parse('2026-09-01T10:00:04.345678Z');
        $pending = SupplierImport::factory()->for($supplier)->create([
            'external_import_id' => 'pending-import',
            'sent_at' => $sentAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'payload' => ['secret' => 'never exposed'],
        ]);
        $processing = SupplierImport::factory()->processing()->for($supplier)->create([
            'external_import_id' => 'processing-import',
            'sent_at' => $sentAt,
            'total_offers' => 3,
            'processed_offers' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $completed = SupplierImport::factory()->completed()->for($supplier)->create([
            'external_import_id' => 'completed-import',
            'sent_at' => $sentAt,
            'total_offers' => 3,
            'processed_offers' => 3,
            'created_at' => $createdAt,
            'updated_at' => $completedAt,
            'completed_at' => $completedAt,
        ]);
        $failed = SupplierImport::factory()->failed()->for($supplier)->create([
            'external_import_id' => 'failed-import',
            'sent_at' => $sentAt,
            'total_offers' => 3,
            'processed_offers' => 0,
            'error_type' => 'Sensitive\\Internal\\Exception',
            'error_message' => 'Import processing failed.',
            'created_at' => $createdAt,
            'updated_at' => $completedAt,
        ]);

        $this->getJson('/api/imports/' . $this->modelId($pending))
            ->assertOk()
            ->assertExactJson(['data' => $this->expectedResource(
                $pending,
                'pending-import',
                'pending',
                0,
                0,
                null,
                null,
            )]);
        $this->getJson('/api/imports/' . $this->modelId($processing))
            ->assertOk()
            ->assertExactJson(['data' => $this->expectedResource(
                $processing,
                'processing-import',
                'processing',
                3,
                0,
                null,
                null,
            )]);
        $this->getJson('/api/imports/' . $this->modelId($completed))
            ->assertOk()
            ->assertExactJson(['data' => $this->expectedResource(
                $completed,
                'completed-import',
                'completed',
                3,
                3,
                null,
                '2026-09-01T10:00:04.345678Z',
            )]);
        $this->getJson('/api/imports/' . $this->modelId($failed))
            ->assertOk()
            ->assertExactJson(['data' => $this->expectedResource(
                $failed,
                'failed-import',
                'failed',
                3,
                0,
                'Import processing failed.',
                null,
            )]);
    }

    public function testEndpointReadsTheCurrentPersistedTransitionState(): void
    {
        $supplierImport = SupplierImport::factory()->create();

        $this->getJson('/api/imports/' . $this->modelId($supplierImport))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $supplierImport->forceFill([
            'status' => ImportStatus::Processing,
            'total_offers' => 7,
            'processed_offers' => 0,
            'started_at' => now()->toImmutable(),
        ])->save();

        $this->getJson('/api/imports/' . $this->modelId($supplierImport))
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.total_offers', 7)
            ->assertJsonPath('data.processed_offers', 0);
    }

    public function testUnknownImportReturnsScopedJsonNotFound(): void
    {
        $this->getJson('/api/imports/999999')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    /** @return array<string, int|string|null> */
    private function expectedResource(
        SupplierImport $supplierImport,
        string $externalImportId,
        string $status,
        int $totalOffers,
        int $processedOffers,
        ?string $error,
        ?string $completedAt,
    ): array {
        return [
            'id' => $this->modelId($supplierImport),
            'supplier' => 'supplier-a',
            'external_import_id' => $externalImportId,
            'sent_at' => '2026-09-01T10:00:00.123456Z',
            'status' => $status,
            'total_offers' => $totalOffers,
            'processed_offers' => $processedOffers,
            'error' => $error,
            'created_at' => '2026-09-01T10:00:02.234567Z',
            'completed_at' => $completedAt,
        ];
    }

    private function modelId(SupplierImport $supplierImport): int
    {
        $id = $supplierImport->getKey();
        self::assertIsInt($id);

        return $id;
    }
}
