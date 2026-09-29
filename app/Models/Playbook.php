<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Playbook de Atendimento — procedimento operacional reutilizável (motor de padronização). */
class Playbook extends Model
{
    use SoftDeletes;

    protected $fillable = ['scope', 'name', 'category', 'color', 'icon', 'active', 'sort_order', 'actions'];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
        'actions'    => 'array',
    ];

    public function scopeForScope($query, string $scope = 'help_desk')
    {
        return $query->where('scope', $scope);
    }
}
