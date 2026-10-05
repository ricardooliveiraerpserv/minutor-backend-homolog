<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RpoTargetAppserver extends Model
{
    protected $table = 'rpo_target_appservers';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];
}
