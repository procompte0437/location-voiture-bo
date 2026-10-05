<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $table = 'avis';

    protected $fillable = [
        'booking_id', 'customer_id', 'partner_id',
        'rating_cleanliness', 'rating_condition', 'rating_welcome',
        'rating_value', 'rating_overall', 'comment',
        'partner_reply', 'partner_replied_at', 'moderation_status',
    ];

    protected function casts(): array
    {
        return ['partner_replied_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
