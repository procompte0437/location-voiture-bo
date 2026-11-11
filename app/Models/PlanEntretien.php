<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanEntretien extends Model
{
    protected $table = 'plans_entretien';

    protected $fillable = [
        'partner_id', 'vehicle_id', 'libelle', 'periodicite_jours', 'periodicite_km',
        'prochaine_echeance_le', 'prochain_kilometrage',
    ];

    protected function casts(): array
    {
        return ['prochaine_echeance_le' => 'date:Y-m-d'];
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
