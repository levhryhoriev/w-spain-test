<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\SupplierImportResource;
use App\Models\SupplierImport;

final class ShowSupplierImportController extends Controller
{
    public function __invoke(SupplierImport $import): SupplierImportResource
    {
        return new SupplierImportResource($import->load('supplier'));
    }
}
