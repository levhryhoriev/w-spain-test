<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<Reservation> */
final class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'client_reference' => fake()->unique()->uuid(),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'check_in' => fn (array $attributes): mixed => self::offer($attributes['offer_id'] ?? null)
                ->getAttribute('check_in'),
            'check_out' => fn (array $attributes): mixed => self::offer($attributes['offer_id'] ?? null)
                ->getAttribute('check_out'),
            'price' => fn (array $attributes): mixed => self::offer($attributes['offer_id'] ?? null)
                ->getAttribute('price'),
            'currency' => fn (array $attributes): mixed => self::offer($attributes['offer_id'] ?? null)
                ->getAttribute('currency'),
        ];
    }

    private static function offer(mixed $offerId): Offer
    {
        if (! is_int($offerId)) {
            throw new LogicException('The reservation factory requires a persisted offer.');
        }

        $offer = Offer::query()->find($offerId);

        if (! $offer instanceof Offer) {
            throw new LogicException('The reservation factory offer does not exist.');
        }

        return $offer;
    }
}
