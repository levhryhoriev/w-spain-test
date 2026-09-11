<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Imports\AcceptSupplierImport;
use App\Http\Requests\StoreSupplierImportRequest;
use App\Http\Resources\AcceptedSupplierImportResource;
use Illuminate\Http\JsonResponse;

final class AcceptSupplierImportController extends Controller
{
    public function __invoke(
        StoreSupplierImportRequest $request,
        AcceptSupplierImport $acceptSupplierImport,
    ): JsonResponse {
        return (new AcceptedSupplierImportResource(
            $acceptSupplierImport->execute($request->validated()),
        ))->response()->setStatusCode(202);
    }
}
