<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AppServer (Protheus) de um ambiente. Metadados em CLARO. */
class EnvAppserver extends Model
{
    use SoftDeletes;

    protected $table = 'env_appservers';

    protected $fillable = [
        'environment_id', 'name', 'version', 'build', 'patch', 'root_path', 'port',
        'ini_secret_id', 'notes', 'created_by',
    ];

    protected $casts = ['port' => 'integer'];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
