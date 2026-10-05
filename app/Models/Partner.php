<?php

namespace App\Models;

use App\Enums\PartnerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    protected $table = 'partenaires';

    protected $fillable = [
        'owner_user_id', 'type', 'company_name', 'rccm', 'nif', 'manager_name',
        'address', 'city', 'logo_path', 'phone', 'whatsapp', 'website',
        'status', 'trust_level', 'commission_rate', 'average_rating',
        'reviews_count', 'validated_by', 'validated_at', 'status_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PartnerStatus::class,
            'commission_rate' => 'decimal:2',
            'average_rating' => 'decimal:2',
            'validated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isApproved(): bool
    {
        return $this->status === PartnerStatus::Approved;
    }
}
