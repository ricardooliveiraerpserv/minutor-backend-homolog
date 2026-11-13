<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Aprenda o Minutor" — vídeo de treinamento (global, não particionado por empresa). */
class TrainingVideo extends Model
{
    public const DIR = 'training-videos';

    /** Perfis do Configurador (mesma ordem da tela). */
    public const AUDIENCES = [
        'admin'                    => 'Admin',
        'administrativo'           => 'Administrativo',
        'coordenador_projetos'     => 'Coordenador de Projetos',
        'coordenador_sustentacao'  => 'Coordenador de Sustentação',
        'consultor_horista'        => 'Consultor Horista',
        'consultor_banco_de_horas' => 'Consultor Banco de Horas',
        'consultor_fixo'           => 'Consultor Fixo',
        'parceiro_gestor'          => 'Parceiro Gestor',
        'parceiro_simples'         => 'Parceiro Simples',
        'cliente'                  => 'Cliente',
    ];

    protected $fillable = [
        'title', 'description', 'category', 'audiences', 'file_path', 'cover_path', 'original_name', 'mime',
        'size', 'duration_seconds', 'version', 'sort_order', 'active', 'content_updated_at',
        'created_by_id', 'updated_by_id',
    ];

    protected $casts = [
        'audiences'          => 'array',
        'active'             => 'boolean',
        'size'               => 'integer',
        'duration_seconds'   => 'integer',
        'version'            => 'integer',
        'sort_order'         => 'integer',
        'content_updated_at' => 'datetime',
    ];

    /** O vídeo é do público de um perfil do Configurador? (vazio = todos). */
    public function visibleToProfile(string $profile): bool
    {
        [$aud, , , $hdPerms] = self::splitAudiences((array) ($this->audiences ?? []));
        if (!$aud && !$hdPerms) return true;
        if (in_array($profile, $aud, true)) return true;
        // Público por permissão do Help Desk: pode haver gente com essa permissão em qualquer perfil interno.
        return $hdPerms && $profile !== 'cliente';
    }

    /** O usuário tem, no PERFIL DE HELP DESK vinculado, a permissão marcada? (sem perfil = não) */
    public static function userHasHdPermission(User $user, string $perm): bool
    {
        if (!$user->helpdesk_access_profile_id) return false;
        $p = HelpDeskAccessProfile::find($user->helpdesk_access_profile_id);
        if (!$p || !$p->enabled || !is_array($p->permissions)) return false;
        return (bool) ($p->permissions[$perm] ?? false);
    }

    /** O usuário pertence ao público do vídeo? (admin vê tudo; audiences vazio = todos). */
    public function visibleTo(User $user): bool
    {
        if ($user->isAdmin()) return true;
        if (!$this->active) return false;
        [$profiles, $customers, $managerOnly, $hdPerms] = self::splitAudiences((array) ($this->audiences ?? []));
        // Público = perfis do Configurador OU permissões do perfil de Help Desk ('hd:tickets.triagem' →
        // todos cujo perfil de HD tem a Triagem marcada). Basta atender um dos dois.
        if ($profiles || $hdPerms) {
            $byProfile = $profiles && array_intersect($profiles, $user->configuradorProfileKeys());
            $byHd = false;
            foreach ($hdPerms as $perm) { if (self::userHasHdPermission($user, $perm)) { $byHd = true; break; } }
            if (!$byProfile && !$byHd) return false;
        }
        // Restrições extras (vídeo exclusivo de um cliente, ex.: Indicadores da AUSTER):
        // 'customer:ID' → só usuários desse cliente; 'cliente_gestor' → só o Gestor do cliente.
        if ($customers && !in_array((int) $user->customer_id, $customers, true)) return false;
        if ($managerOnly && $user->type === 'cliente' && !$user->is_customer_manager) return false;
        return true;
    }

    /** Token de restrição válido em audiences (além das chaves de perfil). */
    public static function isRestrictionToken(string $t): bool
    {
        return $t === 'cliente_gestor' || (bool) preg_match('/^customer:\d+$/', $t)
            || (bool) preg_match('/^hd:[a-z_.]+$/', $t);
    }

    /** @return array{0: string[], 1: int[], 2: bool, 3: string[]} perfis, clientes, só gestor, permissões do HD */
    public static function splitAudiences(array $aud): array
    {
        $aud = array_values(array_filter($aud));
        $customers = []; $profiles = []; $manager = false; $hd = [];
        foreach ($aud as $t) {
            if ($t === 'cliente_gestor') { $manager = true; continue; }
            if (preg_match('/^customer:(\d+)$/', $t, $m)) { $customers[] = (int) $m[1]; continue; }
            if (preg_match('/^hd:([a-z_.]+)$/', $t, $m)) { $hd[] = $m[1]; continue; }
            $profiles[] = $t;
        }
        return [$profiles, $customers, $manager, $hd];
    }
}
