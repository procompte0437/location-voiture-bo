<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AuditLog extends Model
{
    protected $table = 'journaux_audit';

    public $timestamps = true;

    const UPDATED_AT = null; // append-only : pas de mise à jour

    protected $fillable = [
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'action',
        'http_method',
        'url',
        'route',
        'referer',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'browser',
        'browser_version',
        'platform',
        'device_type',
        'request_id',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Les journaux d\'audit sont immuables (mise à jour interdite).');
        });

        static::deleting(function () {
            throw new RuntimeException('Les journaux d\'audit sont immuables (suppression interdite).');
        });
    }
}
