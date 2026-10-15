<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageContact extends Model
{
    protected $table = 'messages_contact';

    protected $fillable = [
        'utilisateur_id',
        'nom',
        'email',
        'telephone',
        'sujet',
        'message',
    ];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
