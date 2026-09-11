<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ImportStatus;
use App\Models\SupplierImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class AcceptedSupplierImportResource extends JsonResource
{
    /** @return array{id: int, status: string} */
    public function toArray(Request $request): array
    {
        $supplierImport = $this->resource;

        if (! $supplierImport instanceof SupplierImport) {
            throw new LogicException('The accepted supplier import resource is invalid.');
        }

        $id = $supplierImport->getKey();
        $status = $supplierImport->getAttribute('status');

        if (! is_int($id) || ! $status instanceof ImportStatus) {
            throw new LogicException('The accepted supplier import resource is invalid.');
        }

        return [
            'id' => $id,
            'status' => $status->value,
        ];
    }
}
