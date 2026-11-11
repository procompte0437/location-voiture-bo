<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouvementCaution extends Model
{
    protected $table = 'mouvements_caution';

    protected $fillable = ['booking_id', 'partner_id', 'type', 'montant', 'motif', 'cree_par'];
}
