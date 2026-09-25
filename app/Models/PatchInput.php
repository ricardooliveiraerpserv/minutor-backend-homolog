<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH — identidade LÓGICA de um .ptm. Zero bytes/path (source_ref opaco; agente resolve on-prem). */
class PatchInput extends Model
{
    protected $table = 'patch_inputs';
    protected $guarded = ['id'];
    protected $casts = ['compatibility' => 'array'];
}
