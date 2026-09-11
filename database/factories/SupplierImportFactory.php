<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierImport> */
final class SupplierImportFactory extends Factory
{
    protected $model = SupplierImport::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => fake()->unique()->uuid(),
            'sent_at' => now()->toImmutable(),
            'status' => ImportStatus::Pending,
            'payload' => ['offers' => []],
            'total_offers' => 0,
            'processed_offers' => 0,
            'error_type' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportStatus::Processing,
            'total_offers' => 0,
            'processed_offers' => 0,
            'started_at' => now()->subMinute()->toImmutable(),
            'completed_at' => null,
            'error_type' => null,
            'error_message' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportStatus::Completed,
            'total_offers' => 0,
            'processed_offers' => 0,
            'started_at' => now()->subMinutes(2)->toImmutable(),
            'completed_at' => now()->subMinute()->toImmutable(),
            'error_type' => null,
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportStatus::Failed,
            'total_offers' => 0,
            'processed_offers' => 0,
            'started_at' => now()->subMinutes(2)->toImmutable(),
            'completed_at' => null,
            'error_type' => 'ImportProcessingFailed',
            'error_message' => 'Supplier import processing failed.',
        ]);
    }
}
