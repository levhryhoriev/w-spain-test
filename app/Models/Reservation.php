<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'client_reference',
        'customer_name',
        'customer_email',
        'check_in',
        'check_out',
        'price',
        'currency',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    protected function casts(): array
    {
        return [
            'offer_id' => 'integer',
            'check_in' => 'immutable_date',
            'check_out' => 'immutable_date',
            'price' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
