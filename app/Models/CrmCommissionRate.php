<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmCommissionRate extends Model
{
    protected $fillable = ['company_id', 'user_id', 'percentual', 'vigencia_inicio', 'vigencia_fim', 'motivo'];
    protected $casts = ['percentual' => 'decimal:2'];
}
