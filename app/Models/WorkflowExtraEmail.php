<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowExtraEmail extends Model
{
    protected $fillable = ['workflow_key', 'email', 'channel'];
}
