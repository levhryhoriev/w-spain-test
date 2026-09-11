<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ImportStatus;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class SupplierImportResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        $supplierImport = $this->resource;

        if (! $supplierImport instanceof SupplierImport) {
            throw new LogicException('The supplier import resource is invalid.');
        }

        $supplier = $supplierImport->getRelation('supplier');
        $id = $supplierImport->getKey();
        $externalImportId = $supplierImport->getAttribute('external_import_id');
        $sentAt = $supplierImport->getAttribute('sent_at');
        $status = $supplierImport->getAttribute('status');
        $totalOffers = $supplierImport->getAttribute('total_offers');
        $processedOffers = $supplierImport->getAttribute('processed_offers');
        $error = $supplierImport->getAttribute('error_message');
        $createdAt = $supplierImport->getAttribute('created_at');
        $completedAt = $supplierImport->getAttribute('completed_at');
        $supplierSlug = $supplier instanceof Supplier ? $supplier->getAttribute('slug') : null;

        if (
            ! is_int($id)
            || ! is_string($supplierSlug)
            || ! is_string($externalImportId)
            || ! $sentAt instanceof CarbonImmutable
            || ! $status instanceof ImportStatus
            || ! is_int($totalOffers)
            || ! is_int($processedOffers)
            || (! is_string($error) && $error !== null)
            || ! $createdAt instanceof CarbonImmutable
            || (! $completedAt instanceof CarbonImmutable && $completedAt !== null)
        ) {
            throw new LogicException('The persisted supplier import is invalid.');
        }

        return [
            'id' => $id,
            'supplier' => $supplierSlug,
            'external_import_id' => $externalImportId,
            'sent_at' => self::formatInstant($sentAt),
            'status' => $status->value,
            'total_offers' => $totalOffers,
            'processed_offers' => $processedOffers,
            'error' => $error,
            'created_at' => self::formatInstant($createdAt),
            'completed_at' => $completedAt instanceof CarbonImmutable
                ? self::formatInstant($completedAt)
                : null,
        ];
    }

    private static function formatInstant(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
