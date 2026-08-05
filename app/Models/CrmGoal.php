<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmGoal extends Model
{
    protected $fillable = ['company_id', 'user_id', 'competencia', 'valor_meta'];
    protected $casts = ['valor_meta' => 'decimal:2'];
}
