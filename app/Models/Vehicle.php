<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'partner_id', 'agency_id', 'brand', 'model', 'year', 'category',
        'plate_number', 'color', 'seats', 'doors', 'luggage', 'transmission',
        'fuel', 'air_conditioning', 'included_km', 'deposit_amount',
        'min_driver_age', 'min_license_years', 'booking_mode',
        'cancellation_policy', 'with_driver_available', 'airport_delivery',
        'free_cancellation', 'fuel_policy', 'features', 'allowed_zones',
        'description', 'price_per_day', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            'air_conditioning' => 'boolean',
            'with_driver_available' => 'boolean',
            'airport_delivery' => 'boolean',
            'free_cancellation' => 'boolean',
            'features' => 'array',
            'allowed_zones' => 'array',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(VehicleMedia::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(VehicleBlock::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim("{$this->brand} {$this->model}");
    }
}
