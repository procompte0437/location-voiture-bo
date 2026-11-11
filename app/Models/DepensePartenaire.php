<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepensePartenaire extends Model
{
    protected $table = 'depenses_partenaires';

    protected $fillable = ['partner_id', 'libelle', 'montant', 'categorie', 'date_depense', 'cree_par'];

    protected function casts(): array
    {
        return ['date_depense' => 'date:Y-m-d'];
    }
}
