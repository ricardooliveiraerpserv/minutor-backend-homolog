<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineViewPermission extends Model
{
    protected $table = 'pipeline_view_permissions';

    protected $fillable = [
        'user_id',
        'demand_visible',
        'demand_client_scope',
        'demand_customer_ids',
        'project_visible',
        'project_client_scope',
        'project_customer_ids',
    ];

    protected $casts = [
        'demand_visible'       => 'boolean',
        'project_visible'      => 'boolean',
        'demand_customer_ids'  => 'array',
        'project_customer_ids' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Permissão EFETIVA de pipeline p/ um usuário: a linha custom se existir, senão o padrão
     * do perfil (admin/administrativo vê as duas partes; demais só a de Projetos).
     * Formato: ['demand'=>['visible','scope','customer_ids'], 'project'=>[...], 'source'].
     */
    public static function effectiveFor(?User $user): array
    {
        if (!$user) {
            return self::defaultFor(false) + ['source' => 'default'];
        }

        $perm = self::where('user_id', $user->id)->first();
        if ($perm) {
            return [
                'demand' => [
                    'visible'      => (bool) $perm->demand_visible,
                    'scope'        => $perm->demand_client_scope ?: 'all',
                    'customer_ids' => array_values(array_map('intval', $perm->demand_customer_ids ?? [])),
                ],
                'project' => [
                    'visible'      => (bool) $perm->project_visible,
                    'scope'        => $perm->project_client_scope ?: 'all',
                    'customer_ids' => array_values(array_map('intval', $perm->project_customer_ids ?? [])),
                ],
                'source' => 'custom',
            ];
        }

        $adminLike = $user->isAdmin() || (method_exists($user, 'isAdministrativo') && $user->isAdministrativo());
        return self::defaultFor($adminLike) + ['source' => 'default'];
    }

    private static function defaultFor(bool $adminLike): array
    {
        return [
            'demand'  => ['visible' => $adminLike, 'scope' => 'all', 'customer_ids' => []],
            'project' => ['visible' => true,       'scope' => 'all', 'customer_ids' => []],
        ];
    }
}
