<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Perfil de Acesso do Help Desk (agente|cliente). PEGADINHA: $table explícito. */
class HelpDeskAccessProfile extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $table = 'helpdesk_access_profiles';

    protected $fillable = ['name', 'kind', 'is_default', 'enabled', 'permissions', 'sort_order'];

    protected $casts = [
        'is_default'  => 'boolean',
        'enabled'     => 'boolean',
        'permissions' => 'array',
        'sort_order'  => 'integer',
    ];
}
