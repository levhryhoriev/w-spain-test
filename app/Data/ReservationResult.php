<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Reservation;

final readonly class ReservationResult
{
    public function __construct(
        public Reservation $reservation,
        public bool $created,
    ) {
    }
}
