<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRecipient extends Model
{
    protected $fillable = ['workflow_key', 'audience', 'channel'];
}
