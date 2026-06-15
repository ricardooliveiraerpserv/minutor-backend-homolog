<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTemplate extends Model
{
    protected $fillable = ['workflow_key', 'subject', 'body', 'recurrence_days'];

    protected $casts = [
        'recurrence_days' => 'integer',
    ];
}
