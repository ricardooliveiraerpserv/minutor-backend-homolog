<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACL fina por usuário × ambiente × operação (custom; sem linha = default do papel). */
class EnvPermission extends Model
{
    protected $table = 'env_permissions';

    public const OPS = ['view', 'reveal', 'copy', 'manage', 'admin'];

    protected $fillable = [
        'user_id', 'environment_id',
        'can_view', 'can_reveal', 'can_copy', 'can_manage', 'can_admin',
    ];

    protected $casts = [
        'can_view' => 'boolean', 'can_reveal' => 'boolean', 'can_copy' => 'boolean',
        'can_manage' => 'boolean', 'can_admin' => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
