<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'birth_date', 'nationality',
        'address', 'city', 'license_number', 'license_obtained_at',
        'license_front_path', 'license_back_path', 'id_document_path',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'license_obtained_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
