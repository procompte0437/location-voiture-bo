<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocument extends Model
{
    protected $table = 'documents_vehicules';

    protected $fillable = ['vehicle_id', 'type', 'file_path', 'expires_at', 'status'];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
