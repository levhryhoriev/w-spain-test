<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Property;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<Offer> */
final class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        $checkIn = now()->addMonth()->startOfDay()->toImmutable();

        return [
            'supplier_import_id' => SupplierImport::factory(),
            'supplier_id' => fn (array $attributes): int => self::supplierId(
                $attributes['supplier_import_id'] ?? null,
            ),
            'property_id' => Property::factory(),
            'external_id' => fake()->unique()->uuid(),
            'check_in' => $checkIn,
            'check_out' => $checkIn->addDays(2),
            'max_guests' => 2,
            'price' => fake()->numberBetween(5_000, 50_000),
            'currency' => 'EUR',
            'available_units' => 3,
            'expires_at' => now()->addDay()->toImmutable(),
            'source_sent_at' => now()->toImmutable(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subSecond()->toImmutable(),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (): array => [
            'available_units' => 0,
        ]);
    }

    public function pricedAt(int $minorUnits): static
    {
        return $this->state(fn (): array => [
            'price' => $minorUnits,
        ]);
    }

    public function sourcedAt(CarbonImmutable $sentAt): static
    {
        return $this->state(fn (): array => [
            'source_sent_at' => $sentAt,
        ]);
    }

    public function forSupplierImport(SupplierImport $supplierImport): static
    {
        $supplierId = $supplierImport->getAttribute('supplier_id');

        return $this->state(fn (): array => [
            'supplier_id' => $supplierId,
            'supplier_import_id' => $supplierImport->getKey(),
        ]);
    }

    private static function supplierId(mixed $supplierImportId): int
    {
        if (! is_int($supplierImportId)) {
            throw new LogicException('The offer factory requires a persisted supplier import.');
        }

        $supplierImport = SupplierImport::query()->find($supplierImportId);

        if (! $supplierImport instanceof SupplierImport) {
            throw new LogicException('The offer factory supplier import does not exist.');
        }

        $supplierId = $supplierImport->getAttribute('supplier_id');

        if (! is_int($supplierId)) {
            throw new LogicException('The offer factory supplier import has no supplier.');
        }

        return $supplierId;
    }
}
