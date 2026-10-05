<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleCategory extends Model
{
    protected $table = 'categories_vehicules';

    protected $fillable = [
        'slug', 'label', 'description', 'sort_order', 'is_active', 'show_on_home',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_home' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'category', 'slug');
    }
}
