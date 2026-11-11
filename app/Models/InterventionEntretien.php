<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterventionEntretien extends Model
{
    protected $table = 'interventions_entretien';

    protected $fillable = [
        'partner_id', 'vehicle_id', 'plan_id', 'description', 'statut', 'prevue_le', 'realisee_le', 'compteur',
    ];

    protected function casts(): array
    {
        return ['prevue_le' => 'date:Y-m-d', 'realisee_le' => 'date:Y-m-d'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEntretien::class, 'plan_id');
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
