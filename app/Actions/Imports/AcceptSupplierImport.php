<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AcceptSupplierImport
{
    /** @param array<array-key, mixed> $validated */
    public function execute(array $validated): SupplierImport
    {
        $supplierSlug = $validated['supplier'] ?? null;
        $externalImportId = $validated['external_import_id'] ?? null;
        $sentAt = $validated['sent_at'] ?? null;
        $offers = $validated['offers'] ?? null;

        if (
            ! is_string($supplierSlug)
            || ! is_string($externalImportId)
            || ! is_string($sentAt)
            || ! is_array($offers)
        ) {
            throw new LogicException('Validated supplier import data is incomplete.');
        }

        $supplier = Supplier::query()->where('slug', $supplierSlug)->firstOrFail();
        $supplierId = $supplier->getKey();

        if (! is_int($supplierId)) {
            throw new LogicException('The supplier must be persisted before accepting an import.');
        }

        try {
            $supplierImport = DB::transaction(function () use (
                $supplierId,
                $externalImportId,
                $sentAt,
                $validated,
                $offers,
            ): SupplierImport {
                $supplierImport = SupplierImport::query()->create([
                    'supplier_id' => $supplierId,
                    'external_import_id' => $externalImportId,
                    'sent_at' => CarbonImmutable::parse($sentAt)->utc(),
                    'status' => ImportStatus::Pending,
                    'payload' => $validated,
                    'total_offers' => count($offers),
                    'processed_offers' => 0,
                ]);
                return $supplierImport;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return SupplierImport::query()
                ->useWritePdo()
                ->where('supplier_id', $supplierId)
                ->where('external_import_id', $externalImportId)
                ->first() ?? throw $exception;
        }

        $supplierImportId = $supplierImport->getKey();

        if (! is_int($supplierImportId)) {
            throw new LogicException('The accepted supplier import must have an integer identity.');
        }

        ProcessImport::dispatch($supplierImportId)->afterCommit();

        return $supplierImport;
    }
}
