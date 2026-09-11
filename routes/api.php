<?php

declare(strict_types=1);

use App\Http\Controllers\AcceptSupplierImportController;
use App\Http\Controllers\CreateReservationController;
use App\Http\Controllers\SearchPropertiesController;
use App\Http\Controllers\ShowSupplierImportController;
use Illuminate\Support\Facades\Route;

Route::post('/imports', AcceptSupplierImportController::class);
Route::get('/imports/{import}', ShowSupplierImportController::class);
Route::get('/properties', SearchPropertiesController::class);
Route::post('/offers/{offer}/reservations', CreateReservationController::class);
