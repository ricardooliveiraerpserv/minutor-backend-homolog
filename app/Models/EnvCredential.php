<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Credencial de um ambiente. username em CLARO; só a SENHA é segredo (secret_id → env_secrets). */
class EnvCredential extends Model
{
    use SoftDeletes;

    protected $table = 'env_credentials';

    public const CATEGORIES = [
        'win_admin', 'sql', 'protheus', 'fluig', 'totvs_license', 'ftp', 'smtp',
        'azure', 'aws', 'gcp', 'o365', 'portal',
    ];

    protected $fillable = [
        'environment_id', 'category', 'label', 'username', 'secret_id', 'url',
        'responsible_user_id', 'last_rotated_at', 'rotate_every_days', 'critical', 'notes', 'created_by',
    ];

    protected $casts = [
        'critical'          => 'boolean',
        'last_rotated_at'   => 'datetime',
        'rotate_every_days' => 'integer',
    ];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
    public function secret(): BelongsTo { return $this->belongsTo(EnvSecret::class, 'secret_id'); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
}
