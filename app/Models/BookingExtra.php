<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingExtra extends Model
{
    protected $table = 'extras_reservations';

    protected $fillable = [
        'booking_id', 'extra_id', 'quantity', 'unit_price', 'total_price',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function extra(): BelongsTo
    {
        return $this->belongsTo(Extra::class);
    }
}
