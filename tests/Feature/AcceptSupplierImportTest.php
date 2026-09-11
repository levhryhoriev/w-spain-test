<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Imports\AcceptSupplierImport;
use App\Actions\Imports\ProcessSupplierImport;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Offer;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PDOException;
use Tests\TestCase;

final class AcceptSupplierImportTest extends TestCase
{
    use DatabaseMigrations;

    public function testNewImportIsPersistedPendingQueuedAndReturnedAsAccepted(): void
    {
        Queue::fake();
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);
        $payload['sent_at'] = '2026-09-01T10:00:00.123+02:00';

        $response = $this->postJson('/api/imports', $payload);

        $supplierImport = SupplierImport::query()->sole();
        $supplierImportId = $supplierImport->getKey();
        self::assertIsInt($supplierImportId);

        $response->assertAccepted()->assertExactJson([
            'data' => [
                'id' => $supplierImportId,
                'status' => 'pending',
            ],
        ]);
        self::assertSame(ImportStatus::Pending, $supplierImport->getAttribute('status'));
        self::assertEquals($payload, $supplierImport->getAttribute('payload'));
        self::assertSame(1, $supplierImport->getAttribute('total_offers'));
        self::assertSame(0, $supplierImport->getAttribute('processed_offers'));
        $sentAt = $supplierImport->getAttribute('sent_at');
        self::assertInstanceOf(\Carbon\CarbonImmutable::class, $sentAt);
        self::assertSame('2026-09-01 08:00:00.123000', $sentAt->format('Y-m-d H:i:s.u'));
        self::assertSame(0, Offer::query()->count());
        Queue::assertPushed(
            ProcessImport::class,
            static fn (ProcessImport $job): bool => $job->supplierImportId === $supplierImportId
                && $job->afterCommit === true,
        );
    }

    public function testInvalidHttpPayloadDoesNotPersistOrDispatch(): void
    {
        Queue::fake();
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);
        data_set($payload, 'offers.0.currency', 'USD');
        self::assertIsArray($payload);

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers.0.currency');

        self::assertSame(0, SupplierImport::query()->count());
        Queue::assertNothingPushed();
    }

    public function testAcceptedImportRunsThroughTheRealJobHandlerToCompletedOffers(): void
    {
        Queue::fake();
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);

        $response = $this->postJson('/api/imports', $payload)->assertAccepted();
        $supplierImport = SupplierImport::query()->sole();
        $job = null;
        Queue::assertPushed(ProcessImport::class, static function (ProcessImport $pushedJob) use (&$job): bool {
            $job = $pushedJob;

            return true;
        });
        self::assertInstanceOf(ProcessImport::class, $job);

        $job->handle(app(ProcessSupplierImport::class));

        $supplierImport->refresh();
        self::assertSame($supplierImport->getKey(), $response->json('data.id'));
        self::assertSame(ImportStatus::Completed, $supplierImport->getAttribute('status'));
        self::assertSame(1, $supplierImport->getAttribute('total_offers'));
        self::assertSame(1, $supplierImport->getAttribute('processed_offers'));
        self::assertSame(1, Offer::query()->count());
        self::assertSame('BCN-0001', Offer::query()->sole()->property()->firstOrFail()->getAttribute('code'));
    }

    public function testUnknownSupplierIsRejectedWithoutPersistenceOrDispatch(): void
    {
        Queue::fake();
        $payload = $this->validPayload();
        self::assertIsArray($payload);
        $payload['supplier'] = 'missing-supplier';

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier');

        self::assertSame(0, SupplierImport::query()->count());
        Queue::assertNothingPushed();
    }

    public function testEveryDuplicateStateReturnsCurrentWinnerAndNeverRedispatches(): void
    {
        Queue::fake();
        $supplier = Supplier::factory()->create(['slug' => 'supplier-a']);

        foreach (ImportStatus::cases() as $status) {
            $externalImportId = 'shared-' . $status->value;
            $originalPayload = $this->validPayload($externalImportId, 1);
            self::assertIsArray($originalPayload);
            $factory = match ($status) {
                ImportStatus::Pending => SupplierImport::factory(),
                ImportStatus::Processing => SupplierImport::factory()->processing(),
                ImportStatus::Completed => SupplierImport::factory()->completed(),
                ImportStatus::Failed => SupplierImport::factory()->failed(),
            };
            $supplierImport = $factory->for($supplier)->create([
                'external_import_id' => $externalImportId,
                'status' => $status,
                'payload' => $originalPayload,
                'total_offers' => 1,
                'processed_offers' => $status === ImportStatus::Completed ? 1 : 0,
                'completed_at' => $status === ImportStatus::Completed ? now()->toImmutable() : null,
            ]);
            $replayPayload = $this->validPayload($externalImportId, 2);
            self::assertIsArray($replayPayload);

            $response = $this->postJson('/api/imports', $replayPayload);

            $response->assertAccepted()->assertExactJson([
                'data' => [
                    'id' => $supplierImport->getKey(),
                    'status' => $status->value,
                ],
            ]);
            self::assertSame(1, SupplierImport::query()
                ->where('supplier_id', $supplier->getKey())
                ->where('external_import_id', $externalImportId)
                ->count());
            self::assertEquals($originalPayload, $supplierImport->fresh()?->getAttribute('payload'));
            self::assertSame(0, DB::connection()->transactionLevel());
        }

        Queue::assertNothingPushed();
    }

    public function testDifferentSuppliersMayReuseTheSameExternalImportIdentity(): void
    {
        Queue::fake();
        Supplier::factory()->create(['slug' => 'supplier-a']);
        Supplier::factory()->create(['slug' => 'supplier-b']);
        $supplierAPayload = $this->validPayload('shared-import', 1);
        $supplierBPayload = $this->validPayload('shared-import', 1);
        self::assertIsArray($supplierAPayload);
        self::assertIsArray($supplierBPayload);
        $supplierBPayload['supplier'] = 'supplier-b';

        $this->postJson('/api/imports', $supplierAPayload)->assertAccepted();
        $this->postJson('/api/imports', $supplierBPayload)->assertAccepted();

        self::assertSame(2, SupplierImport::query()->where('external_import_id', 'shared-import')->count());
        Queue::assertPushed(ProcessImport::class, 2);
    }

    public function testCaseDistinctExternalImportIdentitiesRemainDistinct(): void
    {
        Queue::fake();
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $lowercasePayload = $this->validPayload('case-sensitive', 1);
        $uppercasePayload = $this->validPayload('CASE-SENSITIVE', 1);
        self::assertIsArray($lowercasePayload);
        self::assertIsArray($uppercasePayload);

        $this->postJson('/api/imports', $lowercasePayload)->assertAccepted();
        $this->postJson('/api/imports', $uppercasePayload)->assertAccepted();

        self::assertSame(2, SupplierImport::query()->count());
        Queue::assertPushed(ProcessImport::class, 2);
    }

    public function testDispatchOccursOnlyAfterTheInsertTransactionCommits(): void
    {
        config()->set('queue.default', 'sync');
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);
        $queuedJobs = 0;
        Queue::after(static function () use (&$queuedJobs): void {
            $queuedJobs++;
        });
        DB::beginTransaction();

        app(AcceptSupplierImport::class)->execute($payload);

        self::assertSame(0, $queuedJobs);
        DB::commit();
        self::assertSame(1, $queuedJobs);
    }

    public function testPostCommitQueueUniqueViolationPropagatesWithoutLosingCommittedImport(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);
        $queueException = new UniqueConstraintViolationException(
            'mysql',
            'forced queue failure',
            [],
            new PDOException('forced queue failure'),
        );
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('push')->once()->andThrow($queueException);
        $queueFactory = Mockery::mock(QueueFactoryContract::class);
        $queueFactory->shouldReceive('connection')->once()->andReturn($queue);
        $this->app->instance(QueueFactoryContract::class, $queueFactory);

        try {
            app(AcceptSupplierImport::class)->execute($payload);
            self::fail('The queue exception was expected to propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertSame($queueException, $exception);
        }

        self::assertSame(1, SupplierImport::query()->count());
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    private function validPayload(string $externalImportId = 'import-2026-09-01-001', int $price = 72_500): mixed
    {
        return [
            'supplier' => 'supplier-a',
            'external_import_id' => $externalImportId,
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
                    'property' => [
                        'code' => 'BCN-0001',
                        'name' => 'Apartment near Sagrada Familia',
                        'city' => 'Barcelona',
                    ],
                    'check_in' => '2026-10-10',
                    'check_out' => '2026-10-15',
                    'max_guests' => 4,
                    'price' => $price,
                    'currency' => 'EUR',
                    'available_units' => 2,
                    'expires_at' => '2026-09-10T23:59:59Z',
                ],
            ],
        ];
    }
}
