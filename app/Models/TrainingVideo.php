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
        $aud = array_values(array_filter((array) ($this->audiences ?? [])));
        return !$aud || in_array($profile, $aud, true);
    }

    /** O usuário pertence ao público do vídeo? (admin vê tudo; audiences vazio = todos). */
    public function visibleTo(User $user): bool
    {
        if ($user->isAdmin()) return true;
        if (!$this->active) return false;
        $aud = array_values(array_filter((array) ($this->audiences ?? [])));
        if (!$aud) return true;
        return (bool) array_intersect($aud, $user->configuradorProfileKeys());
    }
}
