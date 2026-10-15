<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH P2 — item físico de uma PatchExecution (pending|started|committed|failed). */
class PatchExecutionItem extends Model
{
    protected $table = 'patch_execution_items';
    protected $guarded = ['id'];
    protected $casts = [
        'started_at'   => 'datetime',
        'committed_at' => 'datetime',
    ];

    public function execution()
    {
        return $this->belongsTo(PatchExecution::class, 'patch_execution_id');
    }

    public function input()
    {
        return $this->belongsTo(PatchInput::class, 'patch_input_id');
    }
}
