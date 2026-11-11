<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembrePartenaire extends Model
{
    protected $table = 'membres_partenaires';

    protected $fillable = ['partner_id', 'user_id', 'role'];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
