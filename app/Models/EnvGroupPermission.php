<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACL por Grupo de Consultores × ambiente — membros do grupo herdam automaticamente. */
class EnvGroupPermission extends Model
{
    protected $table = 'env_group_permissions';

    protected $fillable = [
        'consultant_group_id', 'environment_id',
        'can_view', 'can_reveal', 'can_copy', 'can_manage', 'can_admin',
    ];

    protected $casts = [
        'can_view' => 'boolean', 'can_reveal' => 'boolean', 'can_copy' => 'boolean',
        'can_manage' => 'boolean', 'can_admin' => 'boolean',
    ];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
