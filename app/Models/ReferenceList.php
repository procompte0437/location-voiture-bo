<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceList extends Model
{
    protected $table = 'listes_reference';

    protected $fillable = ['type', 'slug', 'label', 'parent_slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
