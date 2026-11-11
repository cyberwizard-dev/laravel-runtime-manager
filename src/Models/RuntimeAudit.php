<?php

namespace Cyberwizard\RuntimeManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for privileged dashboard actions (retry/flush jobs,
 * trigger migration, clear caches, etc.).
 *
 * @property string $actor
 * @property string $action
 * @property string|null $target
 * @property array|null $context
 * @property string|null $ip
 */
class RuntimeAudit extends Model
{
    protected $table = 'runtime_audit';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    public static function record(string $action, ?string $target = null, array $context = [], ?string $ip = null): self
    {
        return static::create([
            'actor'      => 'dashboard',
            'action'     => $action,
            'target'     => $target,
            'context'    => $context ?: null,
            'ip'         => $ip,
            'created_at' => now(),
        ]);
    }
}
