<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Property> */
final class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('property-????-########'),
            'name' => fake()->company(),
            'city' => fake()->city(),
        ];
    }
}
