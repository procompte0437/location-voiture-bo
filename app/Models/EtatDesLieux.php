<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtatDesLieux extends Model
{
    protected $table = 'etats_des_lieux';

    protected $fillable = ['booking_id', 'type', 'conducteur', 'kilometrage', 'niveau_carburant', 'notes', 'cree_par'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
