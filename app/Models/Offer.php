<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'property_id',
        'supplier_import_id',
        'external_id',
        'check_in',
        'check_out',
        'max_guests',
        'price',
        'currency',
        'available_units',
        'expires_at',
        'source_sent_at',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<SupplierImport, $this> */
    public function supplierImport(): BelongsTo
    {
        return $this->belongsTo(SupplierImport::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'property_id' => 'integer',
            'supplier_import_id' => 'integer',
            'check_in' => 'immutable_date',
            'check_out' => 'immutable_date',
            'max_guests' => 'integer',
            'price' => 'integer',
            'available_units' => 'integer',
            'expires_at' => 'immutable_datetime',
            'source_sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
