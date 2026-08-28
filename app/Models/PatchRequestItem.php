<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH — item do lote (ordem IMUTÁVEL + digest pinado do input no momento da request). */
class PatchRequestItem extends Model
{
    protected $table = 'patch_request_items';
    protected $guarded = ['id'];
}
