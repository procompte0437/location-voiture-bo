<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use App\Support\PiecesJustificatives;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $table = 'vehicules';

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

    public function categoryInfo(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'category', 'slug');
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

    /**
     * Retire un véhicule dont l’assurance ou la visite technique a expiré.
     * Un véhicule sans pièce déposée reste visible : l’obligation se règle à la validation.
     */
    public function scopeDocumentsEnRegle(Builder $requete): Builder
    {
        return $requete->whereDoesntHave('documents', fn (Builder $q) => $q
            ->whereIn('type', PiecesJustificatives::VEHICULE_BLOQUANTES)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today()));
    }

    public function aUnDocumentExpire(): bool
    {
        return $this->documents()
            ->whereIn('type', PiecesJustificatives::VEHICULE_BLOQUANTES)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today())
            ->exists();
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
