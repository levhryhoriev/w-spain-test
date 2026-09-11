<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

final class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::query()->updateOrCreate(['slug' => 'supplier-a']);
        Supplier::query()->updateOrCreate(['slug' => 'supplier-b']);
    }
}
