<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Imports\ProcessSupplierImport;
use App\Enums\ImportStatus;
use App\Models\SupplierImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 55;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $supplierImportId)
    {
    }

    public function handle(ProcessSupplierImport $processSupplierImport): void
    {
        $processSupplierImport->execute($this->supplierImportId);
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->supplierImportId))
                ->releaseAfter(10)
                ->expireAfter(60),
        ];
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $supplierImport = SupplierImport::query()
                ->lockForUpdate()
                ->find($this->supplierImportId);

            if (! $supplierImport instanceof SupplierImport) {
                return;
            }

            if ($supplierImport->getAttribute('status') === ImportStatus::Completed) {
                return;
            }

            $supplierImport->forceFill([
                'status' => ImportStatus::Failed,
                'processed_offers' => 0,
                'error_type' => $exception::class,
                'error_message' => 'Import processing failed.',
                'completed_at' => null,
            ])->save();
        });
    }
}
