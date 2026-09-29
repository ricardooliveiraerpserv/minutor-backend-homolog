<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Banco de dados de um ambiente. Metadados em CLARO; só a senha é segredo. */
class EnvDatabase extends Model
{
    use SoftDeletes;

    protected $table = 'env_databases';

    protected $fillable = [
        'environment_id', 'engine', 'server', 'port', 'instance', 'database', 'username',
        'password_secret_id', 'backup_info', 'always_on', 'critical', 'notes', 'created_by',
    ];

    protected $casts = ['backup_info' => 'array', 'always_on' => 'boolean', 'critical' => 'boolean', 'port' => 'integer'];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
