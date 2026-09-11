<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\SupplierImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupplierImport extends Model
{
    /** @use HasFactory<SupplierImportFactory> */
    use HasFactory;

    protected $table = 'supplier_imports';

    protected $fillable = [
        'supplier_id',
        'external_import_id',
        'sent_at',
        'status',
        'payload',
        'total_offers',
        'processed_offers',
        'error_type',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $hidden = [
        'payload',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'sent_at' => 'immutable_datetime',
            'status' => ImportStatus::class,
            'payload' => 'array',
            'total_offers' => 'integer',
            'processed_offers' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
