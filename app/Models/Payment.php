<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $table = 'paiements';

    protected $fillable = [
        'booking_id', 'gateway', 'method', 'amount', 'currency',
        'status', 'transaction_ref', 'gateway_payload', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
