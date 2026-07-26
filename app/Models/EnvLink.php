<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link/portal de um ambiente (não é segredo — tudo em claro). */
class EnvLink extends Model
{
    protected $table = 'env_links';

    protected $fillable = ['environment_id', 'label', 'url', 'kind', 'created_by'];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
}
