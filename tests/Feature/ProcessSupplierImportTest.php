<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Imports\ProcessSupplierImport;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Tests\TestCase;

final class ProcessSupplierImportTest extends TestCase
{
    use DatabaseMigrations;

    public function testJobCarriesOnlyImportIdentityAndUsesOverlapProtection(): void
    {
        $job = new ProcessImport(42);
        $serialized = serialize($job);
        $middleware = $job->middleware();

        self::assertSame(42, $job->supplierImportId);
        self::assertStringNotContainsString(SupplierImport::class, $serialized);
        self::assertCount(1, $middleware);
        self::assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        self::assertSame('42', $middleware[0]->key);
        self::assertSame(10, $middleware[0]->releaseAfter);
        self::assertSame(60, $middleware[0]->expiresAfter);
        self::assertSame(3, $job->tries);
        self::assertSame(10, $job->backoff);
        self::assertSame(55, $job->timeout);
        self::assertTrue($job->failOnTimeout);
        self::assertSame(90, config('queue.connections.redis.retry_after'));
        self::assertSame(3, config('horizon.defaults.supervisor-1.tries'));
        self::assertSame(60, config('horizon.defaults.supervisor-1.timeout'));
    }

    public function testPendingImportCreatesSortedOffersWithEverySnapshotFieldAndHonestCompletion(): void
    {
        $supplier = Supplier::factory()->create();
        $sentAt = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $supplierImport = $this->supplierImport($supplier, [
            $this->offerPayload('offer-z', 'PROP-Z', 72_500),
            $this->offerPayload('offer-a', 'PROP-A', 54_321),
        ], $sentAt);

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));

        $supplierImport->refresh();
        self::assertSame(ImportStatus::Completed, $supplierImport->getAttribute('status'));
        self::assertSame(2, $supplierImport->getAttribute('total_offers'));
        self::assertSame(2, $supplierImport->getAttribute('processed_offers'));
        self::assertNotNull($supplierImport->getAttribute('started_at'));
        self::assertNotNull($supplierImport->getAttribute('completed_at'));
        self::assertSame(['offer-a', 'offer-z'], Offer::query()->orderBy('id')->pluck('external_id')->all());

        $offer = Offer::query()->where('external_id', 'offer-a')->firstOrFail();
        $checkIn = $offer->getAttribute('check_in');
        $checkOut = $offer->getAttribute('check_out');
        $expiresAt = $offer->getAttribute('expires_at');
        $sourceSentAt = $offer->getAttribute('source_sent_at');
        self::assertInstanceOf(CarbonImmutable::class, $checkIn);
        self::assertInstanceOf(CarbonImmutable::class, $checkOut);
        self::assertInstanceOf(CarbonImmutable::class, $expiresAt);
        self::assertInstanceOf(CarbonImmutable::class, $sourceSentAt);
        self::assertTrue($offer->supplier()->firstOrFail()->is($supplier));
        self::assertTrue($offer->supplierImport()->firstOrFail()->is($supplierImport));
        self::assertSame('PROP-A', $offer->property()->firstOrFail()->getAttribute('code'));
        self::assertSame('2026-10-10', $checkIn->format('Y-m-d'));
        self::assertSame('2026-10-15', $checkOut->format('Y-m-d'));
        self::assertSame(4, $offer->getAttribute('max_guests'));
        self::assertSame(54_321, $offer->getAttribute('price'));
        self::assertSame('EUR', $offer->getAttribute('currency'));
        self::assertSame(2, $offer->getAttribute('available_units'));
        self::assertSame('2026-09-10 23:59:59.654321', $expiresAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-01 10:00:00.123456', $sourceSentAt->format('Y-m-d H:i:s.u'));
    }

    public function testLaterImportUpdatesExistingSupplierOfferWithoutCreatingDuplicate(): void
    {
        $supplier = Supplier::factory()->create();
        $firstImport = $this->supplierImport($supplier, [$this->offerPayload('shared', 'PROP-1', 10_000)]);
        app(ProcessSupplierImport::class)->execute($this->modelId($firstImport));
        $secondPayload = $this->offerPayload('shared', 'PROP-2', 20_000);
        self::assertIsArray($secondPayload);
        $secondPayload['max_guests'] = 6;
        $secondPayload['available_units'] = 9;
        $secondImport = $this->supplierImport(
            $supplier,
            [$secondPayload],
            CarbonImmutable::parse('2026-09-02T10:00:00Z'),
        );

        app(ProcessSupplierImport::class)->execute($this->modelId($secondImport));

        $offer = Offer::query()->sole();
        self::assertSame(20_000, $offer->getAttribute('price'));
        self::assertSame(6, $offer->getAttribute('max_guests'));
        self::assertSame(9, $offer->getAttribute('available_units'));
        self::assertTrue($offer->supplierImport()->firstOrFail()->is($secondImport));
        self::assertSame('PROP-2', $offer->property()->firstOrFail()->getAttribute('code'));
    }

    public function testOlderSnapshotDoesNotOverwriteNewerOfferOrCreateItsProperty(): void
    {
        $supplier = Supplier::factory()->create();
        $olderImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('shared', 'OLDER-PROP', 10_000)],
            CarbonImmutable::parse('2026-09-01T10:00:00Z'),
        );
        $newerImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('shared', 'NEWER-PROP', 20_000)],
            CarbonImmutable::parse('2026-09-02T10:00:00Z'),
        );

        app(ProcessSupplierImport::class)->execute($this->modelId($newerImport));
        app(ProcessSupplierImport::class)->execute($this->modelId($olderImport));

        $offer = Offer::query()->sole();
        self::assertSame(20_000, $offer->getAttribute('price'));
        self::assertTrue($offer->supplierImport()->firstOrFail()->is($newerImport));
        self::assertSame('NEWER-PROP', $offer->property()->firstOrFail()->getAttribute('code'));
        self::assertFalse(Property::query()->where('code', 'OLDER-PROP')->exists());
    }

    public function testEqualTimestampEarlierImportWinsRegardlessOfExecutionOrder(): void
    {
        $supplier = Supplier::factory()->create();
        $sentAt = CarbonImmutable::parse('2026-09-01T10:00:00Z');
        $earlierImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('shared', 'EARLIER-PROP', 10_000)],
            $sentAt,
        );
        $laterImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('shared', 'LATER-PROP', 20_000)],
            $sentAt,
        );

        app(ProcessSupplierImport::class)->execute($this->modelId($laterImport));
        app(ProcessSupplierImport::class)->execute($this->modelId($earlierImport));

        $offer = Offer::query()->sole();
        self::assertSame(10_000, $offer->getAttribute('price'));
        self::assertTrue($offer->supplierImport()->firstOrFail()->is($earlierImport));
        self::assertSame('EARLIER-PROP', $offer->property()->firstOrFail()->getAttribute('code'));

        $supplierTwo = Supplier::factory()->create();
        $firstAccepted = $this->supplierImport(
            $supplierTwo,
            [$this->offerPayload('shared', 'FIRST-PROP', 30_000)],
            $sentAt,
        );
        $secondAccepted = $this->supplierImport(
            $supplierTwo,
            [$this->offerPayload('shared', 'SECOND-PROP', 40_000)],
            $sentAt,
        );

        app(ProcessSupplierImport::class)->execute($this->modelId($firstAccepted));
        app(ProcessSupplierImport::class)->execute($this->modelId($secondAccepted));

        $secondSupplierOffer = Offer::query()->where('supplier_id', $supplierTwo->getKey())->sole();
        self::assertSame(30_000, $secondSupplierOffer->getAttribute('price'));
        self::assertTrue($secondSupplierOffer->supplierImport()->firstOrFail()->is($firstAccepted));
    }

    public function testSameExternalOfferIdentityIsIndependentAcrossSuppliers(): void
    {
        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();
        $importA = $this->supplierImport($supplierA, [$this->offerPayload('shared', 'PROP-1', 10_000)]);
        $importB = $this->supplierImport($supplierB, [$this->offerPayload('shared', 'PROP-1', 20_000)]);

        app(ProcessSupplierImport::class)->execute($this->modelId($importA));
        app(ProcessSupplierImport::class)->execute($this->modelId($importB));

        self::assertSame(2, Offer::query()->where('external_id', 'shared')->count());
        self::assertSame(1, Property::query()->count());
    }

    public function testExistingPropertyIsReusedAndItsFirstMetadataWins(): void
    {
        $supplier = Supplier::factory()->create();
        $property = Property::factory()->create([
            'code' => 'PROP-1',
            'name' => 'Original name',
            'city' => 'Original city',
        ]);
        $supplierImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('offer-1', 'PROP-1', 10_000)],
        );

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));

        $refreshedProperty = $property->fresh();
        self::assertInstanceOf(Property::class, $refreshedProperty);
        self::assertSame(1, Property::query()->count());
        self::assertTrue(Offer::query()->sole()->property()->firstOrFail()->is($property));
        self::assertSame('Original name', $refreshedProperty->getAttribute('name'));
        self::assertSame('Original city', $refreshedProperty->getAttribute('city'));
    }

    public function testPropertyCreationRaceRecoversTheCommittedWinner(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('offer-1', 'RACE-PROP', 10_000)],
        );
        $connectionConfiguration = config('database.connections.mysql');
        self::assertIsArray($connectionConfiguration);
        config()->set('database.connections.race', $connectionConfiguration);
        $competitorInserted = false;
        DB::listen(static function (QueryExecuted $query) use (&$competitorInserted): void {
            if ($competitorInserted || ! str_contains($query->sql, 'from `properties`')) {
                return;
            }

            $competitorInserted = true;
            DB::connection('race')->table('properties')->insert([
                'code' => 'RACE-PROP',
                'name' => 'Concurrent winner',
                'city' => 'Madrid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));
        DB::purge('race');

        self::assertTrue($competitorInserted);
        self::assertSame(1, Property::query()->count());
        self::assertSame('Concurrent winner', Property::query()->sole()->getAttribute('name'));
        self::assertTrue(Offer::query()->sole()->property()->firstOrFail()->is(Property::query()->sole()));
    }

    public function testOfferCreationRaceReloadUsesTheSourceTupleRule(): void
    {
        $supplier = Supplier::factory()->create();
        $property = Property::factory()->create(['code' => 'RACE-OFFER-PROP']);
        $sentAt = CarbonImmutable::parse('2026-09-01T10:00:00Z');
        $winningImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('race-offer', 'RACE-OFFER-PROP', 10_000)],
            $sentAt,
        );
        $losingImport = $this->supplierImport($supplier, [], $sentAt);
        $connectionConfiguration = config('database.connections.mysql');
        self::assertIsArray($connectionConfiguration);
        config()->set('database.connections.race', $connectionConfiguration);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $competitorInserted = false;
        DB::listen(static function (QueryExecuted $query) use (
            &$competitorInserted,
            $supplier,
            $property,
            $losingImport,
            $sentAt,
        ): void {
            if ($competitorInserted || ! str_contains($query->sql, 'from `offers`')) {
                return;
            }

            $competitorInserted = true;
            DB::connection('race')->table('offers')->insert([
                'supplier_id' => $supplier->getKey(),
                'property_id' => $property->getKey(),
                'supplier_import_id' => $losingImport->getKey(),
                'external_id' => 'race-offer',
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => 20_000,
                'currency' => 'EUR',
                'available_units' => 2,
                'expires_at' => '2026-09-10 23:59:59.654321',
                'source_sent_at' => $sentAt->format('Y-m-d H:i:s.u'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        app(ProcessSupplierImport::class)->execute($this->modelId($winningImport));
        DB::purge('race');
        DB::disconnect('mysql');

        $offer = Offer::query()->sole();
        self::assertTrue($competitorInserted);
        self::assertSame(10_000, $offer->getAttribute('price'));
        self::assertTrue($offer->supplierImport()->firstOrFail()->is($winningImport));
    }

    public function testConcurrentDuplicateBlocksOnImportThenObservesCompletionAndExits(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport(
            $supplier,
            [$this->offerPayload('offer-1', 'PROP-1', 10_000)],
        );
        $supplierImportId = $this->modelId($supplierImport);
        DB::beginTransaction();
        SupplierImport::query()->lockForUpdate()->findOrFail($supplierImportId);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$password = getenv('TEST_CHILD_DB_PASSWORD');

if (! is_string($password) || ! str_starts_with($password, 'value:')) {
    throw new RuntimeException('The child database password is unavailable.');
}

$app['config']->set('database.connections.mysql.password', substr($password, 6));
$connectionId = Illuminate\Support\Facades\DB::connection()->selectOne('SELECT CONNECTION_ID() AS connection_id');
fwrite(STDOUT, (string) $connectionId->connection_id.PHP_EOL);
fflush(STDOUT);
fgets(STDIN);
$app->make(App\Actions\Imports\ProcessSupplierImport::class)->execute((int) $argv[1]);
        echo 'completed';
PHP;
        $environment = getenv();
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        self::assertIsString($host);
        self::assertTrue(is_int($port) || is_string($port));
        self::assertIsString($database);
        self::assertIsString($username);
        self::assertIsString($password);
        $environment['APP_ENV'] = 'testing';
        $environment['DB_CONNECTION'] = 'mysql';
        $environment['DB_HOST'] = $host;
        $environment['DB_PORT'] = (string) $port;
        $environment['DB_DATABASE'] = $database;
        $environment['DB_USERNAME'] = $username;
        $environment['TEST_CHILD_DB_PASSWORD'] = 'value:' . $password;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, '--', (string) $supplierImportId],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );
        self::assertIsResource($process);
        $connectionId = trim((string) fgets($pipes[1]));
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        self::assertGreaterThan(0, (int) $connectionId);
        fwrite($pipes[0], "process\n");
        fclose($pipes[0]);
        $observedLockWait = false;

        for ($attempt = 0; $attempt < 1_000; $attempt++) {
            $lockWaitCount = DB::scalar(
                'SELECT COUNT(*) AS aggregate FROM performance_schema.data_lock_waits waits '
                . 'INNER JOIN performance_schema.threads threads '
                . 'ON waits.REQUESTING_THREAD_ID = threads.THREAD_ID '
                . 'WHERE threads.PROCESSLIST_ID = ?',
                [(int) $connectionId],
            );

            if (is_int($lockWaitCount) && $lockWaitCount > 0) {
                $observedLockWait = true;
                break;
            }
        }

        self::assertTrue($observedLockWait);
        self::assertSame('', stream_get_contents($pipes[1]));
        self::assertTrue(proc_get_status($process)['running']);
        stream_set_blocking($pipes[1], true);
        app(ProcessSupplierImport::class)->execute($supplierImportId);
        DB::commit();
        $childResult = stream_get_contents($pipes[1]);
        $childError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame('completed', $childResult);
        self::assertSame('', $childError);
        self::assertSame(0, $exitCode);
        self::assertSame(ImportStatus::Completed, $supplierImport->fresh()?->getAttribute('status'));
        self::assertSame(1, Offer::query()->count());
    }

    public function testZeroItemImportCompletesWithZeroCounts(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport($supplier, []);

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));

        $supplierImport->refresh();
        self::assertSame(ImportStatus::Completed, $supplierImport->getAttribute('status'));
        self::assertSame(0, $supplierImport->getAttribute('total_offers'));
        self::assertSame(0, $supplierImport->getAttribute('processed_offers'));
        self::assertSame(0, Offer::query()->count());
    }

    public function testOfferTransactionRollsBackCompletelyWhileClaimRemainsObservable(): void
    {
        $supplier = Supplier::factory()->create();
        $invalidPayload = $this->offerPayload('offer-z', 'PROP-Z', 10_000);
        self::assertIsArray($invalidPayload);
        $invalidPayload['currency'] = 'USD';
        $supplierImport = $this->supplierImport($supplier, [
            $this->offerPayload('offer-a', 'PROP-A', 10_000),
            $invalidPayload,
        ]);

        try {
            app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));
            self::fail('The invalid persisted currency must fail at the database boundary.');
        } catch (QueryException) {
        }

        $supplierImport->refresh();
        self::assertSame(ImportStatus::Processing, $supplierImport->getAttribute('status'));
        self::assertNotNull($supplierImport->getAttribute('started_at'));
        self::assertSame(0, $supplierImport->getAttribute('processed_offers'));
        self::assertNull($supplierImport->getAttribute('completed_at'));
        self::assertSame(0, Offer::query()->count());
        self::assertSame(0, Property::query()->count());
    }

    public function testRetryFromProcessingCompletesAndCompletedDuplicateExitsIdempotently(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport($supplier, [$this->offerPayload('offer-1', 'PROP-1', 10_000)]);
        $supplierImport->forceFill([
            'status' => ImportStatus::Processing,
            'started_at' => now()->subMinute()->toImmutable(),
        ])->save();

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));
        $offer = Offer::query()->sole();
        $offer->forceFill(['price' => 99_999])->save();
        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));

        $supplierImport->refresh();
        self::assertSame(ImportStatus::Completed, $supplierImport->getAttribute('status'));
        self::assertSame(1, $supplierImport->getAttribute('processed_offers'));
        self::assertSame(1, Offer::query()->count());
        self::assertSame(99_999, Offer::query()->sole()->getAttribute('price'));
    }

    public function testFailedTerminalImportExitsWithoutWriting(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport($supplier, [$this->offerPayload('offer-1', 'PROP-1', 10_000)]);
        $supplierImport->forceFill(['status' => ImportStatus::Failed])->save();

        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));

        self::assertSame(0, Offer::query()->count());
        self::assertSame(ImportStatus::Failed, $supplierImport->fresh()?->getAttribute('status'));
    }

    public function testFinalFailureStoresOnlySanitizedDetailsAndKeepsProgressUnsuccessful(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport($supplier, [$this->offerPayload('offer-1', 'PROP-1', 10_000)]);
        $supplierImport->forceFill([
            'status' => ImportStatus::Processing,
            'processed_offers' => 0,
            'started_at' => now()->toImmutable(),
        ])->save();
        $exception = new RuntimeException('password=secret raw database failure');

        (new ProcessImport($this->modelId($supplierImport)))->failed($exception);

        $supplierImport->refresh();
        self::assertSame(ImportStatus::Failed, $supplierImport->getAttribute('status'));
        self::assertSame(0, $supplierImport->getAttribute('processed_offers'));
        self::assertNull($supplierImport->getAttribute('completed_at'));
        self::assertSame(RuntimeException::class, $supplierImport->getAttribute('error_type'));
        self::assertSame('Import processing failed.', $supplierImport->getAttribute('error_message'));
        self::assertStringNotContainsString('secret', (string) $supplierImport->getAttribute('error_message'));
    }

    public function testSlowerFailureCallbackCannotOverwriteCompletedImport(): void
    {
        $supplier = Supplier::factory()->create();
        $supplierImport = $this->supplierImport($supplier, [$this->offerPayload('offer-1', 'PROP-1', 10_000)]);
        app(ProcessSupplierImport::class)->execute($this->modelId($supplierImport));
        $completedAt = $supplierImport->fresh()?->getAttribute('completed_at');

        (new ProcessImport($this->modelId($supplierImport)))->failed(new RuntimeException('late failure'));

        $supplierImport->refresh();
        self::assertSame(ImportStatus::Completed, $supplierImport->getAttribute('status'));
        self::assertEquals($completedAt, $supplierImport->getAttribute('completed_at'));
        self::assertNull($supplierImport->getAttribute('error_type'));
        self::assertNull($supplierImport->getAttribute('error_message'));
    }

    private function supplierImport(
        Supplier $supplier,
        mixed $offers,
        ?CarbonImmutable $sentAt = null,
    ): SupplierImport {
        self::assertIsArray($offers);

        return SupplierImport::factory()->for($supplier)->create([
            'sent_at' => $sentAt ?? CarbonImmutable::parse('2026-09-01T10:00:00Z'),
            'payload' => [
                'supplier' => $supplier->getAttribute('slug'),
                'external_import_id' => fake()->uuid(),
                'sent_at' => ($sentAt ?? CarbonImmutable::parse('2026-09-01T10:00:00Z'))->toIso8601String(),
                'offers' => $offers,
            ],
            'total_offers' => count($offers),
            'processed_offers' => 0,
        ]);
    }

    private function offerPayload(string $externalId, string $propertyCode, int $price): mixed
    {
        return [
            'external_id' => $externalId,
            'property' => [
                'code' => $propertyCode,
                'name' => 'Imported property',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => $price,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59.654321Z',
        ];
    }

    private function modelId(SupplierImport $supplierImport): int
    {
        $id = $supplierImport->getKey();
        self::assertIsInt($id);

        return $id;
    }
}
