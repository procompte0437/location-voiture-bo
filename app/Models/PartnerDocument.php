<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerDocument extends Model
{
    protected $table = 'documents_partenaires';

    protected $fillable = [
        'partner_id', 'type', 'file_path', 'status', 'rejection_reason', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
