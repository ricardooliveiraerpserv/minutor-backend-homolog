<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH — item imutável do lote de uma PatchRequest (pin do input via item_digest). */
class PatchRequestItem extends Model
{
    protected $table = 'patch_request_items';
    protected $guarded = ['id'];

    public function request()
    {
        return $this->belongsTo(PatchRequest::class, 'patch_request_id');
    }

    public function input()
    {
        return $this->belongsTo(PatchInput::class, 'patch_input_id');
    }
}
