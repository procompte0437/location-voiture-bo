<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReassuranceArgument extends Model
{
    protected $table = 'arguments_reassurance';

    protected $fillable = ['title', 'text', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
